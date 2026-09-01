<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo de inventario POR SEDE.
 *
 * Hasta ahora el saldo vivia en una sola columna del catalogo
 * (products.stock, product_variants.stock, ingredients.stock) que
 * StockMovement::booted() incrementaba. Con varias sedes esa columna ya no
 * puede ser la verdad: 10 unidades no dicen nada si estan repartidas entre
 * dos locales.
 *
 * La verdad pasa a esta tabla, y las columnas del catalogo se conservan como
 * AGREGADO (la suma de las sedes), recalculado en la misma transaccion del
 * movimiento. No es duplicacion por comodidad: es lo que deja intacto todo lo
 * que pregunta "cuanto tiene el negocio en total" - alertas de stock bajo,
 * reportes de inventario, la desactivacion automatica de productos de venta
 * unica y la tienda online - sin reescribirlos en esta fase.
 *
 * Exactamente UNA de product_id / product_variant_id / ingredient_id va con
 * valor, igual que en stock_movements. Los tres uniques se apoyan en eso: en
 * MySQL varios NULL no colisionan entre si, asi que conviven sin estorbarse.
 * Un producto CON variantes no tiene fila propia (su stock es la suma de las
 * variantes, misma "columna fantasma" que ya documenta ProductAvailability).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();

            // decimal y no int: los ingredientes se miden en fracciones
            // (0.5 kg) y stock_movements.quantity ya es decimal(14,4).
            $table->decimal('stock', 14, 4)->default(0);

            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->unique(['branch_id', 'product_variant_id']);
            $table->unique(['branch_id', 'ingredient_id']);
            $table->index(['business_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_stocks');
    }
};
