<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del cobro en linea de un pedido.
 *
 * `payment_reference` es la que genera el Payments Core (`pay_...`) y es la
 * llave con la que el webhook encuentra este pedido cuando el pago se
 * aprueba: sin ella, un pago confirmado no tiene a que pedido pertenecer.
 *
 * `payment_url` se guarda para que el comprador pueda volver a pagar desde
 * la pagina de seguimiento si cerro la pestaña a mitad de camino, que es
 * exactamente cuando se pierde una venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_provider', 32)->nullable()->after('status');
            $table->string('payment_reference', 80)->nullable()->after('payment_provider');
            $table->string('payment_url', 512)->nullable()->after('payment_reference');
            $table->timestamp('paid_at')->nullable()->after('confirmed_at');

            // Por donde entra el webhook: encontrar el pedido por la
            // referencia del Core tiene que ser un indice, no un scan.
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_reference']);
            $table->dropColumn(['payment_provider', 'payment_reference', 'payment_url', 'paid_at']);
        });
    }
};
