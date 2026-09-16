<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIXTURE — not application code. THE SYNTHETIC VIOLATION (unsequenced drop).
 *
 * A pure contract step that names no expand step. Nothing in the tree shows the
 * column stopped being used in an earlier change, so nothing rules out this drop
 * shipping alongside the code that stopped using it.
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
