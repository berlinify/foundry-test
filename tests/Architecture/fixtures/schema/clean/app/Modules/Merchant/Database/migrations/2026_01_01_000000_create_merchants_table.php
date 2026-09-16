<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIXTURE — not application code. Establishes that the Merchant module owns
 * `merchants`, which is what makes a foreign key pointed at it cross-module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("merchants", function (Blueprint $table): void {
            $table->id();
            $table->uuid("public_id");
            $table->string("display_name");
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("merchants");
    }
};
