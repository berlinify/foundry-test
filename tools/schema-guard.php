<?php

declare(strict_types=1);

/**
 * schema-guard — no foreign key may cross a module boundary (TAD §7.1).
 *
 * §4.1 forbids a module from reading or writing another module's tables but says
 * nothing about the database enforcing a link between them. A real FOREIGN KEY
 * across a boundary is exactly what blocks the later service extraction §4 promises,
 * so cross-module references are logical ids only, with integrity enforced in the
 * owning module's application layer.
 *
 * Table ownership is resolved from the tree itself: whichever module's
 * `Database/migrations` contains the `Schema::create()` owns the table. Laravel's
 * own `database/migrations` owns framework infrastructure tables as `@framework`;
 * a module may not point a foreign key at those either.
 *
 * Usage:
 *   php tools/schema-guard.php [--root=<directory>]
 *
 * Exit codes: 0 clean · 1 violations found · 2 bad invocation.
 *
 * Runs on a bare PHP binary with no Composer autoload and no framework boot, so
 * CI and a developer's shell execute exactly the same code path.
 */

namespace PayStar\Tools;

require __DIR__.'/lib/migration-source.php';

const CREATE_PATTERN = '/Schema::(?:connection\s*\(\s*[^)]*\)\s*->)?(?<op>createIfNotExists|create|table)\s*\(\s*[\'"](?<table>[A-Za-z0-9_]+)[\'"]/';

$root = guard_root($argv);
$report = new GuardReport('schema-guard', $root);
$migrations = MigrationSource::collect($root);

// ---------------------------------------------------------------------------
// Phase 1 — resolve table ownership. Exactly one module may create a table
// (TAD §7.1, "every table has exactly one writing module").
// ---------------------------------------------------------------------------

/** @var array<string, array{module: string, file: string, line: int}> $owners */
$owners = [];

foreach ($migrations as $migration) {
    foreach (creates($migration) as $create) {
        $table = $create['table'];

        if (isset($owners[$table]) && $owners[$table]['module'] !== $migration['module']) {
            $report->fail($migration['relative'], $create['line'], sprintf(
                "Table `%s` is created by two modules: `%s` (%s:%d) and `%s` here.\n".
                'Every table has exactly one writing module (TAD §7.1). Ownership must be unambiguous '.
                'before a foreign key across it can be judged.',
                $table,
                $owners[$table]['module'],
                $owners[$table]['file'],
                $owners[$table]['line'],
                $migration['module'],
            ));

            continue;
        }

        $owners[$table] ??= [
            'module' => $migration['module'],
            'file' => $migration['relative'],
            'line' => $create['line'],
        ];
    }
}

// ---------------------------------------------------------------------------
// Phase 2 — every foreign key must stay inside its owning module.
// ---------------------------------------------------------------------------

foreach ($migrations as $migration) {
    foreach (foreignKeys($migration) as $fk) {
        $definingOwner = $owners[$fk['table']]['module'] ?? $migration['module'];

        if ($fk['references'] === null) {
            $report->fail($migration['relative'], $fk['line'], sprintf(
                "Foreign key on `%s`.`%s` names no table this guard can resolve (`%s`).\n".
                'Pass the referenced table explicitly — `->constrained(\'merchants\')` rather than '.
                '`->constrained()` on an unconventional column, and never `foreignIdFor(Model::class)`, '.
                'because an Eloquent model may not be reached from a migration the guard reads statically.',
                $fk['table'],
                $fk['column'] ?? '?',
                trim($fk['raw']),
            ));

            continue;
        }

        $referencedOwner = $owners[$fk['references']]['module'] ?? null;

        if ($referencedOwner === null) {
            $report->fail($migration['relative'], $fk['line'], sprintf(
                "Foreign key `%s` on table `%s` references `%s`, which no migration in this repository creates.\n".
                'Ownership cannot be proven, so the constraint cannot be proven intra-module. Create the '.
                'referenced table in the same module, or drop the constraint and keep a logical id.',
                $fk['constraint'],
                $fk['table'],
                $fk['references'],
            ));

            continue;
        }

        if ($referencedOwner === $definingOwner) {
            continue;
        }

        $report->fail($migration['relative'], $fk['line'], sprintf(
            "Foreign key `%s` crosses a module boundary.\n".
            "    constraint : %s\n".
            "    on table   : %s  (owned by %s)\n".
            "    references : %s  (owned by %s)\n".
            'No database-level foreign key may cross a module boundary (TAD §7.1). Keep `%s` as a plain '.
            'logical id and enforce integrity in %s\'s application layer.',
            $fk['constraint'],
            $fk['constraint'],
            $fk['table'],
            $definingOwner,
            $fk['references'],
            $referencedOwner,
            $fk['column'] ?? $fk['constraint'],
            $referencedOwner,
        ));
    }
}

$report->note(sprintf('%d table(s) owned across %d module(s).', count($owners), count(array_unique(array_column($owners, 'module')))));

exit($report->emit(count($migrations)));

// ---------------------------------------------------------------------------

/**
 * Tables the migration creates, taken from `up()` only — `down()` legitimately
 * drops what `up()` made.
 *
 * @param  array{source: string, ...}  $migration
 * @return list<array{table: string, line: int}>
 */
function creates(array $migration): array
{
    $up = upBody($migration);
    if ($up === null) {
        return [];
    }

    if (! preg_match_all(CREATE_PATTERN, $up['body'], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $creates = [];

    foreach ($matches as $match) {
        if ($match['op'][0] === 'table') {
            continue;
        }

        $creates[] = [
            'table' => $match['table'][0],
            'line' => MigrationSource::lineAt($up['body'], $match[0][1], $up['line']),
        ];
    }

    return $creates;
}

/**
 * Every foreign key declared in `up()`, with the table it sits on.
 *
 * @param  array{source: string, ...}  $migration
 * @return list<array{table: string, column: string|null, constraint: string, references: string|null, line: int, raw: string}>
 */
function foreignKeys(array $migration): array
{
    $up = upBody($migration);
    if ($up === null) {
        return [];
    }

    $keys = [];

    foreach (tableSegments($up['body']) as $segment) {
        foreach (statements($segment['body']) as $statement) {
            $found = referencedTable($statement['body']);
            if ($found === null) {
                continue;
            }

            $line = MigrationSource::lineAt(
                $up['body'],
                $segment['offset'] + $statement['offset'],
                $up['line'],
            );

            $keys[] = [
                'table' => $segment['table'],
                'column' => $found['column'],
                'constraint' => $found['constraint'] ?? ($found['column'] ?? 'unnamed'),
                'references' => $found['table'],
                'line' => $line,
                'raw' => $statement['body'],
            ];
        }
    }

    return $keys;
}

/**
 * @param  array{source: string, ...}  $migration
 * @return array{body: string, line: int}|null
 */
function upBody(array $migration): ?array
{
    return MigrationSource::method(MigrationSource::withoutComments($migration['source']), 'up');
}

/**
 * Split a method body into `Schema::create/table('x', ...)` regions, so every
 * statement can be attributed to the table it operates on.
 *
 * @return list<array{table: string, body: string, offset: int}>
 */
function tableSegments(string $body): array
{
    if (! preg_match_all(CREATE_PATTERN, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $segments = [];
    $count = count($matches);

    foreach ($matches as $index => $match) {
        $start = $match[0][1];
        $end = $index + 1 < $count ? $matches[$index + 1][0][1] : strlen($body);

        $segments[] = [
            'table' => $match['table'][0],
            'body' => substr($body, $start, $end - $start),
            'offset' => $start,
        ];
    }

    return $segments;
}

/**
 * Statement-level split. Closure bodies split too, which is exactly what is wanted:
 * each column definition in a blueprint closure becomes its own statement.
 *
 * @return list<array{body: string, offset: int}>
 */
function statements(string $body): array
{
    $parts = preg_split('/;/', $body, -1, PREG_SPLIT_OFFSET_CAPTURE);
    if ($parts === false) {
        return [];
    }

    return array_values(array_map(
        static fn (array $part): array => ['body' => $part[0], 'offset' => $part[1]],
        $parts,
    ));
}

/**
 * Resolve the table a single statement points a foreign key at.
 *
 * Returns null when the statement declares no foreign key at all; returns a row
 * with `table => null` when it declares one whose target cannot be resolved
 * statically — that is a failure, not something to wave through.
 *
 * @return array{column: string|null, constraint: string|null, table: string|null}|null
 */
function referencedTable(string $statement): ?array
{
    $column = null;
    $constraint = null;

    if (preg_match('/->\s*foreign\s*\(\s*[\'"](?<column>[^\'"]+)[\'"]\s*(?:,\s*[\'"](?<name>[^\'"]+)[\'"]\s*)?\)/', $statement, $m) === 1) {
        $column = $m['column'];
        $constraint = ($m['name'] ?? '') !== '' ? $m['name'] : null;
    } elseif (preg_match('/->\s*(?:foreignId|foreignUuid|foreignUlid)\s*\(\s*[\'"](?<column>[^\'"]+)[\'"]/', $statement, $m) === 1) {
        $column = $m['column'];
    } elseif (preg_match('/->\s*(?:[A-Za-z]+)\s*\(\s*[\'"](?<column>[^\'"]+)[\'"]/', $statement, $m) === 1) {
        $column = $m['column'];
    }

    // `->on('table')`, the long form after `->foreign()->references()`.
    if (preg_match('/->\s*on\s*\(\s*[\'"](?<table>[A-Za-z0-9_]+)[\'"]\s*\)/', $statement, $m) === 1) {
        return ['column' => $column, 'constraint' => $constraint, 'table' => $m['table']];
    }

    // Raw SQL, e.g. DB::statement('... REFERENCES merchants(id) ...').
    if (preg_match('/\bREFERENCES\s+["\'`]?(?<table>[A-Za-z0-9_]+)/i', $statement, $m) === 1) {
        return ['column' => $column, 'constraint' => $constraint, 'table' => $m['table']];
    }

    // `->constrained()` / `->constrained('table')` / `->constrained(table: 'table')`.
    if (preg_match('/->\s*constrained\s*\(\s*(?<args>[^)]*)\)/', $statement, $m) === 1) {
        $args = trim($m['args']);

        if ($args === '') {
            return ['column' => $column, 'constraint' => $constraint, 'table' => tableFromColumn($column)];
        }

        if (preg_match('/^(?:table\s*:\s*)?[\'"](?<table>[A-Za-z0-9_]+)[\'"]/', $args, $a) === 1) {
            return ['column' => $column, 'constraint' => $constraint, 'table' => $a['table']];
        }

        return ['column' => $column, 'constraint' => $constraint, 'table' => null];
    }

    // `foreignIdFor(Model::class)` — unresolvable by design: a migration may not
    // reach an Eloquent model across a boundary, so the guard refuses to guess.
    if (preg_match('/->\s*foreignIdFor\s*\(/', $statement) === 1) {
        return ['column' => $column, 'constraint' => $constraint, 'table' => null];
    }

    return null;
}

/**
 * Laravel's own convention: `merchant_id` on `->constrained()` means `merchants`.
 * Only the regular cases are handled; anything irregular resolves to null and is
 * reported, which forces the author to name the table explicitly.
 */
function tableFromColumn(?string $column): ?string
{
    if ($column === null) {
        return null;
    }

    $stem = preg_replace('/_(id|uuid|ulid)$/', '', $column);
    if ($stem === null || $stem === '' || $stem === $column && ! str_ends_with($column, '_id')) {
        return null;
    }

    if (preg_match('/(s|x|z|ch|sh)$/i', $stem)) {
        return $stem.'es';
    }

    if (preg_match('/[^aeiou]y$/i', $stem)) {
        return substr($stem, 0, -1).'ies';
    }

    return $stem.'s';
}
