<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves the three merge-blocking checks, in both directions, against committed
 * fixtures — one test per row of the story's I/O and edge-case matrix.
 *
 * A guard nobody has watched fail is not a guard: every `Given a pull request
 * containing a synthetic violation` case below is a real tree on disk, run through
 * the same script the pipeline runs, asserting the same non-zero exit the pipeline
 * would block the merge on.
 *
 * These tests shell out on purpose. The exit code *is* the contract — the thing
 * `.gitlab-ci.yml` reacts to — so asserting on a return value from an in-process
 * call would test something the pipeline never sees.
 */
final class BoundaryFixtureTest extends TestCase
{
    private const FIXTURES = __DIR__.'/fixtures';

    // -----------------------------------------------------------------------
    // Module dependency boundaries — deptrac
    // -----------------------------------------------------------------------

    #[Test]
    public function boundary_check_passes_when_a_module_uses_another_modules_contracts(): void
    {
        $result = $this->deptrac('boundary/clean');

        $this->assertSame(0, $result['exit'], "deptrac should allow Contracts-only access:\n".$result['output']);
        $this->assertStringContainsString('Violations           0', $result['output']);
    }

    #[Test]
    public function boundary_check_allows_any_module_to_depend_on_platform(): void
    {
        $result = $this->deptrac('boundary/platform-only');

        $this->assertSame(0, $result['exit'], "Platform is the one module everything may reach:\n".$result['output']);
        $this->assertStringContainsString('Violations           0', $result['output']);
    }

    #[Test]
    public function boundary_check_fails_when_a_module_reaches_into_another_modules_internals(): void
    {
        $result = $this->deptrac('boundary/violation');

        $this->assertSame(1, $result['exit'], "deptrac should reject a cross-module internal dependency:\n".$result['output']);

        // The pipeline's reader has to be able to act on this without opening the config.
        $this->assertStringContainsString('Modules\Payment\Application\RecordPaymentDirectly', $result['output'], 'names the source');
        $this->assertStringContainsString('Modules\Merchant\Infrastructure\MerchantModel', $result['output'], 'names the target');
        $this->assertStringContainsString('must not depend on', $result['output'], 'names the rule');
    }

    // -----------------------------------------------------------------------
    // No foreign key across a module boundary — tools/schema-guard.php
    // -----------------------------------------------------------------------

    #[Test]
    public function schema_guard_passes_on_a_foreign_key_inside_one_module(): void
    {
        $result = $this->guard('schema-guard', 'schema/clean');

        $this->assertSame(0, $result['exit'], "An intra-module foreign key is legal:\n".$result['output']);
        $this->assertStringContainsString('schema-guard: OK', $result['output']);
    }

    #[Test]
    public function schema_guard_fails_on_a_foreign_key_that_crosses_a_module_boundary(): void
    {
        $result = $this->guard('schema-guard', 'schema/cross-module-fk');

        $this->assertSame(1, $result['exit'], "A cross-module foreign key must block the merge:\n".$result['output']);

        $this->assertStringContainsString('crosses a module boundary', $result['output']);
        $this->assertStringContainsString('merchant_id', $result['output'], 'names the constraint');
        $this->assertStringContainsString('payments', $result['output'], 'names the table');
        $this->assertStringContainsString('merchants', $result['output'], 'names the referenced table');
        $this->assertStringContainsString('2026_01_02_000000_create_payments_table.php', $result['output'], 'names the migration');
    }

    // -----------------------------------------------------------------------
    // Expand/contract migration discipline — tools/migration-guard.php
    // -----------------------------------------------------------------------

    #[Test]
    public function migration_guard_passes_on_a_correctly_sequenced_expand_and_contract(): void
    {
        $result = $this->guard('migration-guard', 'migration/clean');

        $this->assertSame(0, $result['exit'], "A drop that follows a separate, earlier expand step is legal:\n".$result['output']);
        $this->assertStringContainsString('migration-guard: OK', $result['output']);
    }

    #[Test]
    public function migration_guard_fails_when_a_drop_ships_with_the_change_that_replaces_it(): void
    {
        $result = $this->guard('migration-guard', 'migration/mixed-expand-and-contract');

        $this->assertSame(1, $result['exit'], "Expand and contract in one migration must block the merge:\n".$result['output']);

        $this->assertStringContainsString('Destructive and additive changes in one migration', $result['output']);
        $this->assertStringContainsString('2026_05_01_000000_replace_legacy_reference_on_payments.php', $result['output'], 'names the migration');
    }

    #[Test]
    public function migration_guard_fails_when_a_drop_names_no_earlier_expand_step(): void
    {
        $result = $this->guard('migration-guard', 'migration/unreferenced-drop');

        $this->assertSame(1, $result['exit'], "A drop with nothing sequencing it must block the merge:\n".$result['output']);

        $this->assertStringContainsString('@contract-of', $result['output']);
        $this->assertStringContainsString('2026_05_01_000000_drop_legacy_reference_from_payments.php', $result['output'], 'names the migration');
    }

    #[Test]
    public function migration_guard_fails_on_an_irreversible_migration_with_no_marker(): void
    {
        $result = $this->guard('migration-guard', 'migration/irreversible');

        $this->assertSame(1, $result['exit'], "An undeclared one-way migration must block the merge:\n".$result['output']);

        $this->assertStringContainsString('No `down()` and no `@irreversible` marker', $result['output']);
        $this->assertStringContainsString('2026_05_01_000000_encrypt_payment_references.php', $result['output'], 'names the migration');
    }

    // -----------------------------------------------------------------------
    // The repository itself
    // -----------------------------------------------------------------------

    #[Test]
    public function every_guard_passes_on_this_repository(): void
    {
        $boundaries = $this->execute([PHP_BINARY, $this->root().'/vendor/bin/deptrac', 'analyse', '--config-file='.$this->root().'/deptrac.yaml', '--no-progress', '--no-cache']);
        $this->assertSame(0, $boundaries['exit'], "deptrac on the real tree:\n".$boundaries['output']);

        foreach (['schema-guard', 'migration-guard'] as $guard) {
            $result = $this->execute([PHP_BINARY, $this->root()."/tools/{$guard}.php"]);
            $this->assertSame(0, $result['exit'], "{$guard} on the real tree:\n".$result['output']);
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Runs the repository's real deptrac.yaml — the same 38 layers and the same
     * ruleset CI uses — against a fixture tree, by rewriting only its `paths`.
     * Copying the ruleset into the fixture would prove the copy, not the config.
     *
     * @return array{exit: int, output: string}
     */
    private function deptrac(string $fixture): array
    {
        $config = (string) file_get_contents($this->root().'/deptrac.yaml');
        $rewritten = preg_replace(
            '/^  paths:\n    - \.\/app$/m',
            '  paths:'."\n".'    - '.self::FIXTURES.'/'.$fixture.'/app',
            $config,
            1,
            $count,
        );

        $this->assertSame(1, $count, 'deptrac.yaml no longer has the expected `paths: - ./app` block; this test can no longer retarget it.');

        $temporary = tempnam(sys_get_temp_dir(), 'deptrac-fixture-').'.yaml';
        file_put_contents($temporary, (string) $rewritten);

        try {
            return $this->execute([PHP_BINARY, $this->root().'/vendor/bin/deptrac', 'analyse', '--config-file='.$temporary, '--no-progress', '--no-cache', '--no-ansi']);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function guard(string $guard, string $fixture): array
    {
        return $this->execute([
            PHP_BINARY,
            $this->root()."/tools/{$guard}.php",
            '--root='.self::FIXTURES.'/'.$fixture,
        ]);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, output: string}
     */
    private function execute(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $this->root());

        $this->assertIsResource($process, 'Could not start: '.implode(' ', $command));

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $stdout.$stderr];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
