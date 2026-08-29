<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pasarela PROPIA de cada negocio.
 *
 * No confundir con la de Nexolu (`services.payments_core.api_key`, global),
 * que es con la que le cobramos la suscripcion al dueño del negocio. Esta es
 * con la que el negocio le cobra a SU comprador, y la plata va directo a su
 * cuenta -- Nexolu nunca la custodia.
 *
 * Tabla nueva: no la toca el monolito.
 *
 * `integration_api_key` y `webhook_secret` son secretos de verdad y van
 * cifrados con la llave de la app (cast `encrypted`), no en claro: un dump
 * de la base no puede entregar la capacidad de cobrar en nombre de un
 * comercio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // wompi | bold. Sin enum: los proveedores los da de alta el
            // Payments Core, y un enum obligaria a un ALTER por cada uno.
            $table->string('provider_slug', 32);
            $table->string('environment', 16)->default('production');

            // Identidad del negocio DENTRO del Payments Core.
            $table->string('payments_core_merchant_id', 64)->nullable();
            $table->text('integration_api_key')->nullable();
            $table->text('webhook_secret')->nullable();

            $table->boolean('is_active')->default(false);
            $table->timestamp('connected_at')->nullable();
            // Ultimo error de conexion, para que el comerciante vea por que
            // no le funciona en vez de un interruptor que no hace nada.
            $table->string('last_error', 300)->nullable();
            $table->timestamps();

            // Un negocio puede tener Wompi Y Bold configurados (Bold ademas
            // le sirve para el datafono), pero uno solo de cada proveedor.
            $table->unique(['business_id', 'provider_slug'], 'uq_gateway_business_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_gateways');
    }
};
