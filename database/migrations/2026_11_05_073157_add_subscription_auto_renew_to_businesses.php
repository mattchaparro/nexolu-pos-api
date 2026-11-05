<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renovacion automatica de la suscripcion con el medio de pago guardado (ver
 * subscriptions:renew). Aditiva: el legacy no lee estas columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Prendida por defecto: quien guarda una tarjeta o Nequi lo hace
            // para no tener que volver a pagar a mano. Se apaga desde
            // Suscripcion.
            $table->boolean('subscription_auto_renew')->default(true);
            // Ultimo dia en que subscriptions:renew evaluo al negocio (cobro
            // o aviso). Evita cobrar o avisar dos veces el mismo dia.
            $table->date('subscription_renewal_checked_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['subscription_auto_renew', 'subscription_renewal_checked_on']);
        });
    }
};
