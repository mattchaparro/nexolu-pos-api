<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de producto. Hasta ahora el catalogo no tenia ninguna:
 * `products.image` es un varchar que nunca se lleno porque nadie construyo
 * la pantalla para subirlas. La tienda online las vuelve indispensables, y
 * de paso le sirven al POS para reconocer productos de un vistazo.
 *
 * Una fila = una foto ya procesada (redimensionada y en WebP), con su
 * miniatura. `product_variant_id` opcional para la foto propia de una
 * variante (el rojo y el azul de la misma camiseta); las que no apuntan a
 * ninguna variante son las del producto.
 *
 * business_id denormalizado (mismo criterio que product_variants) para
 * escopear sin join a products - importante porque el storefront publico
 * consulta estas filas sin usuario autenticado.
 *
 * `disk` se guarda por fila y no solo en config: hoy el disco por defecto es
 * el local y manana sera DigitalOcean Spaces, y las fotos ya subidas tienen
 * que seguir resolviendose contra el disco donde realmente estan en vez de
 * romperse el dia que cambie la variable de entorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('thumbnail_path');
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
