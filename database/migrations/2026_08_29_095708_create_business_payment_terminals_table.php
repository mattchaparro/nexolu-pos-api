<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los datafonos del negocio, y los cobros disparados contra ellos.
 *
 * Dos tablas nuevas: el monolito no las toca.
 *
 * `terminal_charges` existe porque un cobro con datafono NO es instantaneo.
 * El backend le dice a Bold que muestre el monto, y despues hay que esperar
 * a que el cliente pase la tarjeta. Sin una fila que sobreviva a esa espera
 * no habria nada que consultar (la caja hace polling) ni con que conciliar
 * el webhook cuando llega.
 *
 * La venta NO se crea aca. Nace despues, cuando la caja confirma el cobro
 * aprobado - misma regla que en la tienda online: la venta es el hecho
 * economico y aparece cuando entro la plata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Serial Y modelo: Bold exige los dos para cobrar, el serial
            // solo no alcanza.
            $table->string('serial', 64);
            $table->string('model', 32);
            // Como lo bautizo el comerciante en su app ("Caja 1"). Es lo
            // unico que le sirve al cajero para elegirlo.
            $table->string('name', 120)->nullable();
            $table->string('status', 24)->default('BINDED');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'serial'], 'uq_terminal_business_serial');
        });

        Schema::create('terminal_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_payment_terminal_id')->nullable()->constrained()->nullOnDelete();

            // La referencia del Payments Core: la llave con la que el webhook
            // encuentra este cobro.
            $table->string('reference', 80)->unique();
            $table->string('provider_slug', 32)->default('bold');
            $table->string('provider_charge_id', 80)->nullable();

            $table->decimal('amount', 12, 2);
            // pending | approved | declined | error | voided | expired |
            // consumed. `consumed` = ya se convirtio en venta; sin ese
            // estado, un cobro aprobado podria facturarse dos veces.
            $table->string('status', 20)->default('pending');
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_reason', 300)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_charges');
        Schema::dropIfExists('business_payment_terminals');
    }
};
