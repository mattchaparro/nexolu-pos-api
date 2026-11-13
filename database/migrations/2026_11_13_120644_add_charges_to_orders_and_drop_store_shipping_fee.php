<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un solo envío, una sola propina, un solo impoconsumo: los del negocio.
 *
 * La tienda tenía su propia tarifa de envío (`shipping_flat_fee`), aparte del
 * domicilio del negocio. Eran dos números para lo mismo y el cobro de un
 * pedido terminaba mezclándolos. Ahora el pedido cotiza con la
 * configuración del negocio, también la propina y el impoconsumo, y los
 * guarda para que su total se explique solo.
 *
 * La tarifa de la tienda era la que veían los compradores, así que donde
 * había una se pasa al negocio antes de borrarla (en producción: Delicious
 * Moments, sin domicilios en el POS, y Restaurante de prueba).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('service_charge', 12, 2)->default(0)->after('shipping_fee');
            $table->decimal('ipoconsumo', 12, 2)->default(0)->after('service_charge');
        });

        DB::table('business_store_settings')
            ->where('shipping_flat_fee', '>', 0)
            ->orderBy('id')
            ->get(['business_id', 'shipping_flat_fee'])
            ->each(fn (object $settings) => DB::table('businesses')
                ->where('id', $settings->business_id)
                ->update(['delivery_enabled' => true, 'delivery_fee' => $settings->shipping_flat_fee]));

        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('shipping_flat_fee');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->decimal('shipping_flat_fee', 10, 2)->default(0)->after('whatsapp_number');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['service_charge', 'ipoconsumo']);
        });
    }
};
