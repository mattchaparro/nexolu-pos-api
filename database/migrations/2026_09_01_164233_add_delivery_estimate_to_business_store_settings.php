<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuanto se demora un pedido en llegar, en palabras del comerciante.
 *
 * La pagina de seguimiento decia el estado ("Recibido", "Enviado") pero no
 * cuando esperar el pedido, que es lo primero que quiere saber quien acaba
 * de pagar. No se puede calcular: depende de la ciudad, del transportador y
 * de como opere cada negocio. Asi que lo escribe el comerciante ("2 a 3 dias
 * habiles") y se muestra tal cual.
 *
 * Texto libre y no un numero de dias a proposito: un restaurante que
 * despacha en 40 minutos y una tienda que envia por transportadora no caben
 * en la misma unidad.
 *
 * `business_store_settings` es tabla NUEVA de esta migracion (el monolito no
 * la conoce), asi que agregarle una columna no toca al legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->string('delivery_estimate', 120)->nullable()->after('shipping_flat_fee');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('delivery_estimate');
        });
    }
};
