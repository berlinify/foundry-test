<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIXTURE — not application code. A legitimately one-way migration.
 *
 * Reversibility is impossible once the plaintext is gone, so it says so and says
 * what an operator does instead. This is the only accepted way to ship a migration
 * with no `down()` (TAD §16.2).
 *
 * @irreversible
 * @rollback Restore the payments table from the pre-deploy snapshot taken by the
 *           release runbook, then redeploy the previous application version. The
 *           plaintext references cannot be recovered from the encrypted column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE payments SET reference = md5(reference) WHERE reference IS NOT NULL');
    }
};
