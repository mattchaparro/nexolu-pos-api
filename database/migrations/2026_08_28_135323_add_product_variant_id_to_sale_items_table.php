<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable a proposito: sale_items.product_id sigue apuntando SIEMPRE al
 * producto padre (compatibilidad con reportes y con el legacy, que sigue
 * escribiendo esta tabla y no conoce product_variants). Esta columna solo
 * se llena cuando la linea vendio una variante concreta - ver
 * SaleService::applyItems(). Mismo cascadeOnDelete que ya tiene
 * sale_items_product_id_foreign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
