<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIXTURE — not application code. THE LEGAL SHAPE.
 *
 * `payment_attempts.payment_id` is a real foreign key because both tables belong to
 * the Payment module. `payments.merchant_id` is a bare logical id with no
 * constraint, because `merchants` belongs to the Merchant module — integrity for it
 * is enforced in Merchant's application layer (TAD §7.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('public_id');

            // Cross-module reference: logical id only, deliberately unconstrained.
            $table->unsignedBigInteger('merchant_id');

            $table->bigInteger('amount_rial');
            $table->timestampsTz();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // Intra-module reference: a real constraint with explicit delete behaviour.
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
