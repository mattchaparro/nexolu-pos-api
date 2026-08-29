<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una combinacion de atributos activada manualmente por el comerciante
 * (ver decision de negocio en docs de la feature "productos con
 * variaciones"): precio/costo/stock/sku propios, no heredados del
 * producto padre. business_id denormalizado (mismo criterio que
 * Ingredient/StockMovement) para escopear sin join a products.
 * SoftDeletes: sale_items/stock_movements pueden seguir apuntando a una
 * variante borrada, la referencia historica no debe desaparecer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->decimal('price', 10, 2);
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->unsignedSmallInteger('low_stock_alert_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'sku']);
            $table->index('product_id');
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
