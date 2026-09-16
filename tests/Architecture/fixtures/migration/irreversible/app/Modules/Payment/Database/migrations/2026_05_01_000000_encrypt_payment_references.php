<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIXTURE — not application code. THE SYNTHETIC VIOLATION (undeclared one-way).
 *
 * No `down()`, no `@irreversible` marker, no `@rollback` plan. It is indistinguishable
 * at review time from a migration whose author simply forgot to write `down()`, which
 * is precisely why the guard rather than the reviewer has to catch it (TAD §16.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE payments SET reference = md5(reference) WHERE reference IS NOT NULL');
    }
};
