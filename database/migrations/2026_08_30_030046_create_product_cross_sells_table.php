<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas cruzadas: que sugerir cuando alguien lleva un producto.
 *
 * Tabla NUEVA, el monolito legacy no la conoce.
 *
 * DIRIGIDA y no simetrica a proposito: "papas" es buena sugerencia para una
 * hamburguesa, pero sugerir la hamburguesa a quien lleva papas no tiene el
 * mismo sentido. El comerciante decide en que direccion vale, y si quiere
 * las dos crea las dos filas.
 *
 * Sirve tanto al mostrador como a la tienda online: en el POS es el "¿papas
 * con eso?" del cajero -- que es donde de verdad se factura y le sirve a un
 * negocio que nunca abre tienda -- y en la tienda es el "va bien con" de la
 * ficha y el carrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cross_sells', function (Blueprint $table) {
            $table->id();

            // Explicito y no derivado del producto: asi el global scope de
            // BelongsToBusiness filtra sin joins, igual que en el resto.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();

            // El comerciante ordena: la primera sugerencia es la que mas se ve.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Una sugerencia por par: lo garantiza la base, no la validacion.
            $table->unique(['product_id', 'related_product_id']);
            // El listado por producto, en orden.
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cross_sells');
    }
};
