<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuracion de la tienda online de un negocio: como se ve y como vende.
 *
 * Separada de `businesses` a proposito, y no como un puñado de columnas mas
 * en esa tabla: `businesses` es la unica que comparten TODAS las apps del
 * ecosistema y que el monolito legacy sigue escribiendo, asi que crecerla
 * con datos de un modulo opcional es la clase de acoplamiento que despues no
 * se puede deshacer. Tabla nueva = cero riesgo con el legacy.
 *
 * Dos interruptores distintos y a proposito: el feature flag `online_store`
 * (SuperAdmin, decision comercial) habilita el MODULO, y `is_active` de aca
 * (el comerciante, decision operativa) publica la tienda. Un negocio puede
 * tener el modulo habilitado y la tienda todavia cerrada mientras la arma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_store_settings', function (Blueprint $table) {
            $table->id();
            // Unico: un negocio tiene una sola tienda. Si algun dia hay
            // varias, sera otra tabla, no una fila mas aca.
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('is_active')->default(false);

            // Identidad publica. store_name nullable: si no lo llenan, se cae
            // a businesses.name en vez de obligar a repetirlo.
            $table->string('store_name')->nullable();
            $table->text('description')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('primary_color', 7)->nullable();

            // Contacto y venta. whatsapp_number aparte del de businesses: el
            // numero de atencion al comprador no tiene por que ser el mismo
            // con el que el dueño recibe las alertas del POS.
            $table->string('whatsapp_number', 20)->nullable();
            $table->decimal('shipping_flat_fee', 10, 2)->default(0);
            $table->decimal('min_order_amount', 10, 2)->default(0);
            $table->boolean('pickup_enabled')->default(false);
            $table->text('terms')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_store_settings');
    }
};
