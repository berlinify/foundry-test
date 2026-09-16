<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIXTURE — not application code. THE SYNTHETIC VIOLATION.
 *
 * Identical to the clean fixture except that `payments.merchant_id` is constrained
 * against `merchants`, a table the Merchant module owns. This is the merge request
 * the schema check exists to block: it reads as ordinary good practice, it is
 * fully compliant with TAD §4.1, and it is exactly what makes the later service
 * extraction impossible (TAD §7.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('public_id');

            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();

            $table->bigInteger('amount_rial');
            $table->timestampsTz();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('status');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
