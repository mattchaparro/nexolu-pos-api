<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de la tienda online.
 *
 * Un pedido NO es una venta. `sales` solo tiene los estados open|closed,
 * anular es un borrado fisico y `sales.user_id` es NOT NULL - nada de eso
 * sirve para algo que nace de un desconocido a las once de la noche y pasa
 * por confirmacion, preparacion y despacho. La venta se crea cuando el
 * comerciante confirma el pedido: ahi es cuando el compromiso existe y el
 * stock sale de verdad, y `orders.sale_id` las enlaza.
 *
 * Las lineas guardan NOMBRE Y PRECIO copiados, no solo el product_id: el
 * comerciante puede subir el precio o renombrar el producto mientras el
 * pedido esta en curso, y un pedido tiene que poder leerse dentro de un año
 * tal y como se hizo.
 *
 * Todo son tablas nuevas: cero riesgo con el monolito legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Consecutivo por negocio y visible para el comprador ("#14"),
            // distinto del id global que no se le enseña a nadie.
            $table->unsignedInteger('number');
            $table->string('status', 20)->default('pending');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->string('customer_name');
            $table->string('customer_phone', 30);
            $table->string('customer_email')->nullable();

            // Entrega: a domicilio o recoge en tienda.
            $table->boolean('is_pickup')->default(false);
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city', 80)->nullable();
            $table->text('shipping_notes')->nullable();

            // Token no adivinable: es la unica llave del comprador para
            // seguir su pedido sin tener cuenta.
            $table->string('public_token', 40)->unique();

            // Reserva blanda: mientras no venza, estas unidades no se le
            // ofrecen a nadie mas (ver StorefrontProductResource).
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // nullOnDelete y no cascade: borrar un producto del catalogo no
            // puede borrar el historial de lo que alguien compro.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Copias del momento de la compra.
            $table->string('product_name');
            $table->string('variant_label')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);

            $table->timestamps();
            $table->index('order_id');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // Nulo cuando lo movio el sistema (creacion, expiracion).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
