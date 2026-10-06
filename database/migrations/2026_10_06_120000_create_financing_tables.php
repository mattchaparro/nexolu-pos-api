<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Venta financiada por un tercero (Addi, Banti, Sistecredito...): el cliente
// paga una inicial y la financiadora le gira al negocio el resto. Cada venta
// asi deja un credito por cobrarle a esa financiadora.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financing_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name', 80);
            $table->unsignedSmallInteger('expected_payout_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::create('financing_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('financing_provider_id');
            $table->decimal('amount', 12, 2);
            $table->string('approval_number', 80)->nullable();
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_identification', 50)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->date('expected_payout_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_method', 50)->nullable();
            $table->string('payout_reference', 120)->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            $table->foreign('financing_provider_id')->references('id')->on('financing_providers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financing_credits');
        Schema::dropIfExists('financing_providers');
    }
};
