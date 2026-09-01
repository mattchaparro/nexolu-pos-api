<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Como se explica el envio, en palabras del comerciante.
 *
 * El costo ya se guardaba (`shipping_flat_fee`), pero el TEXTO estaba
 * quemado: las plantillas de inicio traian frases fijas ("Envio a todo el
 * pais", "Llega en 2 a 5 dias habiles") que no todos los negocios pueden
 * cumplir. Quien solo reparte en su barrio quedaba prometiendo cobertura
 * nacional hasta que se acordara de editar cada bloque a mano.
 *
 * Se guarda aca, junto al costo, y las plantillas lo citan con el token
 * `{envio}` en vez de repetirlo: cambiarlo una vez lo cambia en toda la
 * tienda (ver StoreTokens en el storefront).
 *
 * `business_store_settings` es tabla nueva, que el monolito no conoce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->string('shipping_note', 160)->nullable()->after('shipping_flat_fee');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('shipping_note');
        });
    }
};
