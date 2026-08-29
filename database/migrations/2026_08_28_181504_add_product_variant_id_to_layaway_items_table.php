<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable a proposito, mismo criterio que sale_items/stock_movements/
 * purchase_lines. product_id sigue apuntando siempre al producto padre;
 * product_variant_id se llena solo cuando el item reservado es una
 * variante concreta - ver LayawayService y
 * StockService::reserveVariantForLayaway().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layaway_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('layaway_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
