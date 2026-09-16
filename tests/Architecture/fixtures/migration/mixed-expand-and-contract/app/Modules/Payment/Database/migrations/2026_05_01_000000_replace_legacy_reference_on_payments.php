<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIXTURE — not application code. THE SYNTHETIC VIOLATION (expand/contract).
 *
 * Adds the replacement column and drops the old one in a single change — so the
 * destructive step ships in the same release as the code that stops using the
 * column, and there is no window in which a rollback still has its data. This is
 * the migration TAD §16.2 forbids, and it is the one that reads most like a tidy
 * piece of work.
 *
 * @contract-of 2026_01_01_000000_create_payments_table
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('reference')->nullable();
            $table->dropColumn('legacy_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('legacy_reference')->nullable();
            $table->dropColumn('reference');
        });
    }
};
