<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desde que sede despacha la tienda online.
 *
 * Con varias sedes, "hay 12 unidades" deja de ser una respuesta: el
 * comprador de internet compra de un inventario concreto, el que se va a
 * empacar. Sin esta columna el storefront mostraria el agregado del negocio
 * y podria vender algo que solo existe en el otro local, a dos horas.
 *
 * Nullable: significa "la sede principal". Es lo correcto para el monosede
 * (que es todo negocio hoy) y evita tener que backfillear una decision que
 * el comerciante no ha tomado. Ver BusinessStoreSettings::fulfillmentBranchId().
 *
 * nullOnDelete y no cascade: si la sede de despacho se borra en duro, la
 * tienda vuelve a la principal - jamas se lleva por delante la
 * configuracion entera de la tienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->foreignId('fulfillment_branch_id')->nullable()->after('business_id')
                ->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropForeign(['fulfillment_branch_id']);
            $table->dropColumn('fulfillment_branch_id');
        });
    }
};
