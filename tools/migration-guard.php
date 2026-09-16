<?php

declare(strict_types=1);

/**
 * migration-guard — expand/contract migration discipline (TAD §16.2, NFR16).
 *
 * Three rules, each statically decidable from the tree alone:
 *
 *   1. REVERSIBLE OR DECLARED IRREVERSIBLE.
 *      A migration defines a `down()` with a real body, or it carries both
 *      `@irreversible` and an `@rollback` note of at least 20 characters saying
 *      what an operator does instead. A silent one-way migration fails.
 *
 *   2. DESTRUCTIVE STEPS SHIP ALONE.
 *      A migration whose `up()` drops or renames anything may not also add
 *      anything. Expand and contract are separate changes, by definition.
 *
 *   3. A DESTRUCTIVE STEP NAMES THE EXPAND STEP IT FOLLOWS.
 *      It carries `@contract-of <migration>` naming a migration that already
 *      exists in this repository with a strictly earlier timestamp. This is what
 *      makes "no destructive change in the same release as the code that stops
 *      using the column" checkable without a diff: the drop cannot be authored
 *      until the step it contracts is already in the tree.
 *
 * Rule 3 approximates "release" by migration ordering, which is the strongest
 * statement a static guard can make. It cannot prove the *application code* stopped
 * using the column — it forces the author to point at the change that did, and
 * refuses the drop that points at nothing.
 *
 * Usage:
 *   php tools/migration-guard.php [--root=<directory>]
 *
 * Exit codes: 0 clean · 1 violations found · 2 bad invocation.
 *
 * Runs on a bare PHP binary with no Composer autoload and no framework boot, so
 * CI and a developer's shell execute exactly the same code path.
 */

namespace PayStar\Tools;

require __DIR__.'/lib/migration-source.php';

/**
 * Operations that remove or rename existing schema. Detected in `up()` only —
 * `down()` is *supposed* to be destructive.
 */
const DESTRUCTIVE = [
    '/Schema::(?:connection\s*\(\s*[^)]*\)\s*->)?drop(?:IfExists)?\s*\(/' => 'drops a table',
    '/Schema::(?:connection\s*\(\s*[^)]*\)\s*->)?rename\s*\(/' => 'renames a table',
    '/->\s*dropColumn\s*\(/' => 'drops a column',
    '/->\s*dropConstrainedForeignId\s*\(/' => 'drops a column',
    '/->\s*renameColumn\s*\(/' => 'renames a column',
    '/->\s*dropSoftDeletes(?:Tz)?\s*\(/' => 'drops a column',
    '/->\s*dropTimestamps(?:Tz)?\s*\(/' => 'drops columns',
    '/->\s*dropMorphs\s*\(/' => 'drops columns',
    '/->\s*dropRememberToken\s*\(/' => 'drops a column',
    '/\bDROP\s+(?:COLUMN|TABLE)\b/i' => 'drops schema in raw SQL',
    '/\bRENAME\s+(?:COLUMN|TO)\b/i' => 'renames schema in raw SQL',
];

/**
 * Operations that add schema. A column type list rather than "anything that is
 * not a drop", so that indexes and foreign keys on already-present columns do not
 * count as expansion.
 */
const ADDITIVE = [
    '/Schema::(?:connection\s*\(\s*[^)]*\)\s*->)?create(?:IfNotExists)?\s*\(/' => 'creates a table',
    '/->\s*addColumn\s*\(/' => 'adds a column',
    '/->\s*(?:id|bigIncrements|increments|uuid|ulid|string|char|text|tinyText|mediumText|longText|integer|tinyInteger|smallInteger|mediumInteger|bigInteger|unsignedBigInteger|unsignedInteger|unsignedSmallInteger|unsignedTinyInteger|float|double|decimal|boolean|enum|set|json|jsonb|date|dateTime|dateTimeTz|time|timeTz|timestamp|timestampTz|timestamps|timestampsTz|softDeletes|softDeletesTz|year|binary|uuidMorphs|morphs|nullableMorphs|ipAddress|macAddress|geometry|foreignId|foreignUuid|foreignUlid|foreignIdFor|rememberToken)\s*\(/' => 'adds a column',
    '/\bADD\s+COLUMN\b/i' => 'adds a column in raw SQL',
];

$root = guard_root($argv);
$report = new GuardReport('migration-guard', $root);
$migrations = MigrationSource::collect($root);

/** @var array<string, string> $known migration stem => ordering key */
$known = [];
foreach ($migrations as $migration) {
    $known[$migration['name']] = $migration['order'];
}

$destructiveCount = 0;

foreach ($migrations as $migration) {
    $stripped = MigrationSource::withoutComments($migration['source']);
    $comments = MigrationSource::comments($migration['source']);
    $up = MigrationSource::method($stripped, 'up');
    $down = MigrationSource::method($stripped, 'down');

    // -- Rule 1 --------------------------------------------------------------

    // An annotation must start its own line (after the docblock asterisk). Prose
    // that merely mentions `@irreversible` is documentation, not a declaration —
    // without this, a migration could talk its way past the guard.
    $irreversible = preg_match('/^[ \t]*(?:\*[ \t]*)?@irreversible\b/m', $comments) === 1;
    $rollback = preg_match('/^[ \t]*(?:\*[ \t]*)?@rollback[ \t]+(?<plan>.+)$/m', $comments, $m) === 1 ? trim($m['plan']) : '';

    if ($irreversible) {
        if (mb_strlen($rollback) < 20) {
            $report->fail($migration['relative'], null, sprintf(
                "Marked `@irreversible` without a usable rollback plan.\n".
                'Add `@rollback <what an operator does instead>` — at least 20 characters. Got: %s',
                $rollback === '' ? 'nothing' : '"'.$rollback.'"',
            ));
        }
    } elseif ($down === null) {
        $report->fail($migration['relative'], null,
            "No `down()` and no `@irreversible` marker.\n".
            'Every migration is reversible, or explicitly flagged irreversible with a written '.
            'rollback plan (TAD §16.2). Add a `down()`, or a docblock carrying `@irreversible` '.
            'and `@rollback <plan>`.'
        );
    } elseif (trim($down['body']) === '') {
        $report->fail($migration['relative'], $down['line'],
            "`down()` is empty, which is an undeclared one-way migration.\n".
            'Either reverse the change, or declare `@irreversible` with an `@rollback <plan>` note.'
        );
    }

    if ($up === null) {
        $report->fail($migration['relative'], null, 'No `up()` method — this is not a migration.');

        continue;
    }

    // -- Rules 2 and 3 -------------------------------------------------------

    $destructive = matched(DESTRUCTIVE, $up);
    if ($destructive === []) {
        continue;
    }

    $destructiveCount++;
    $first = $destructive[0];

    $additive = matched(ADDITIVE, $up);
    if ($additive !== []) {
        $report->fail($migration['relative'], $first['line'], sprintf(
            "Destructive and additive changes in one migration: it %s (line %d) and %s (line %d).\n".
            'Expand and contract are separate releases (TAD §16.2): add nullable → backfill in batches '.
            '→ switch code → make non-null → drop old. Split this into two migrations.',
            $first['what'],
            $first['line'],
            $additive[0]['what'],
            $additive[0]['line'],
        ));

        continue;
    }

    if (preg_match('/^[ \t]*(?:\*[ \t]*)?@contract-of[ \t]+(?<ref>\S+)/m', $comments, $m) !== 1) {
        $report->fail($migration['relative'], $first['line'], sprintf(
            "Destructive migration (it %s) with no `@contract-of` reference.\n".
            'A contract step must name the earlier migration after which the column stopped being '.
            'used — `@contract-of 2026_01_01_000000_add_nullable_foo`. Without it there is nothing '.
            'proving this drop is not shipping alongside the code that stopped using the column.',
            $first['what'],
        ));

        continue;
    }

    $reference = rtrim(basename($m['ref']), '.php');

    if (! isset($known[$reference])) {
        $report->fail($migration['relative'], $first['line'], sprintf(
            "`@contract-of %s` names a migration that does not exist in this repository.\n".
            'The expand step must already be in the tree before its contract step may be authored.',
            $reference,
        ));

        continue;
    }

    if ($known[$reference] >= $migration['order']) {
        $report->fail($migration['relative'], $first['line'], sprintf(
            "`@contract-of %s` is not an earlier migration (%s vs this migration's %s).\n".
            'The expand step must precede its contract step, or they are the same change.',
            $reference,
            $known[$reference],
            $migration['order'],
        ));
    }
}

$report->note(sprintf('%d destructive migration(s), each with a named prior expand step.', $destructiveCount));

exit($report->emit(count($migrations)));

// ---------------------------------------------------------------------------

/**
 * Every pattern in $catalogue that fires inside the method body, with line numbers.
 *
 * @param  array<string, string>  $catalogue  pattern => human description
 * @param  array{body: string, line: int}  $method
 * @return list<array{what: string, line: int}> ordered by position in the body
 */
function matched(array $catalogue, array $method): array
{
    $hits = [];

    foreach ($catalogue as $pattern => $what) {
        if (! preg_match_all($pattern, $method['body'], $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $hits[] = [
                'what' => $what,
                'line' => MigrationSource::lineAt($method['body'], $match[1], $method['line']),
                'offset' => $match[1],
            ];
        }
    }

    usort($hits, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

    return array_values(array_map(
        static fn (array $hit): array => ['what' => $hit['what'], 'line' => $hit['line']],
        $hits,
    ));
}
