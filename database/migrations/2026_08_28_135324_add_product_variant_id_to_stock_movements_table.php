<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable a proposito, mismo criterio que sale_items.product_variant_id:
 * stock_movements.product_id sigue apuntando siempre al producto padre;
 * esta columna solo se llena cuando el movimiento afecto el stock de una
 * variante concreta - ver StockMovement::booted() y
 * StockService::registerVariantSale().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
