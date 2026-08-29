<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable a proposito, mismo criterio que sale_items/stock_movements.
 * product_variant_id: la variante concreta comprada; product_id sigue
 * apuntando siempre al producto padre (compatibilidad con reportes) - ver
 * PurchaseService::applyProductLine() y StockService::registerVariantPurchase().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
