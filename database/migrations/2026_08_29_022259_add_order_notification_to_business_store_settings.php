<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso por correo cuando entra un pedido.
 *
 * Un pedido llega sin que nadie toque el POS: la bandeja tiene contador y se
 * refresca sola, pero eso solo sirve si alguien esta mirando la pantalla. El
 * correo es el unico canal que hoy funciona sin tramite - WhatsApp exige una
 * plantilla aprobada por Meta (ver AppointmentWhatsappNotifier), y esa
 * aprobacion no depende de este repo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->boolean('order_email_enabled')->default(true)->after('pickup_enabled');
            // Vacio = al correo del dueño. Existe para la tienda que quiere
            // que los pedidos lleguen a quien despacha, no a quien factura.
            $table->string('order_email')->nullable()->after('order_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn(['order_email_enabled', 'order_email']);
        });
    }
};
