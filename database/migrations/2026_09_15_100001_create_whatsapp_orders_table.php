<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un carrito entrante de WhatsApp (webhook `order` de Meta), tabla PROPIA
 * de esta API a proposito: `sales` la comparte el monolito legacy y no se
 * toca su esquema. El vinculo con la venta que el pedido genero (o no) vive
 * aca, junto con el payload crudo para reprocesar y el motivo cuando quedo
 * en revision.
 *
 * `wamid` unico = idempotencia dura ademas del Cache::add del dispatcher:
 * si el cache expiro (6h) y Meta reintenta, el pedido igual no se duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('wamid')->unique();
            $table->string('phone', 32);
            $table->string('customer_name')->nullable();
            $table->json('raw_order');
            // created: la venta abierta se creo sola | pending_review: algo
            // no valido (stock, producto, precio) y espera a un humano |
            // rejected: un humano lo descarto.
            $table->string('status', 20)->default('pending_review');
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_orders');
    }
};
