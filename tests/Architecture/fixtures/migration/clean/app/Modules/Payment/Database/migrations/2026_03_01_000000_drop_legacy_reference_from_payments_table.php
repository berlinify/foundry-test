<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIXTURE — not application code. THE CONTRACT STEP, done correctly.
 *
 * It drops and does nothing else, and it names the expand step it follows. Because
 * `2026_02_01_000000_add_reference_to_payments_table` is already in the tree with an
 * earlier timestamp, this drop demonstrably is not shipping in the same change as
 * the one that stopped using the column (TAD §16.2).
 *
 * @contract-of 2026_02_01_000000_add_reference_to_payments_table
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('legacy_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('legacy_reference')->nullable();
        });
    }
};
