<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien inicio el cobro: el dueño desde Suscripcion ('checkout') o la
 * renovacion automatica ('auto_renew'). Con eso el webhook sabe si avisar
 * por WhatsApp que se renovo o que fallo, y el job no repite un cobro que
 * todavia esta esperando respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_checkout_orders', function (Blueprint $table) {
            $table->string('origin', 20)->default('checkout');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
