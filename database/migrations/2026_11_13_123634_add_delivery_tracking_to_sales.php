<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domicilios: a dónde va una venta, quién la lleva y en qué va.
 *
 * Vive en la venta y no en el pedido online porque un domicilio también nace
 * en el POS (Vender o una cuenta abierta marcada como domicilio): la venta es
 * lo único que tienen en común. `delivery_status` es nulo en toda venta vieja,
 * así que el historial no aparece en la pantalla de Domicilios. El monolito
 * legacy ya no escribe en `sales` (apagado el 5-nov), y todo es nullable.
 *
 * El cliente tiene ademas su direccion habitual: es con la que se llena la
 * entrega, pero cada venta guarda la suya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('delivery_address')->nullable()->after('delivery_fee');
            // pending (por llevar) -> on_the_way (en camino) -> delivered.
            $table->string('delivery_status', 20)->nullable()->after('delivery_address');
            $table->foreignId('courier_id')->nullable()->after('delivery_status')->constrained('users')->nullOnDelete();
            $table->timestamp('delivery_taken_at')->nullable()->after('courier_id');
            $table->timestamp('delivered_at')->nullable()->after('delivery_taken_at');
            $table->index(['business_id', 'delivery_status']);
        });

        // La direccion habitual del cliente, aparte de la de cada entrega:
        // si se muda, los domicilios viejos conservan a donde se llevaron.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('address')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('address');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'delivery_status']);
            $table->dropConstrainedForeignId('courier_id');
            $table->dropColumn(['delivery_address', 'delivery_status', 'delivery_taken_at', 'delivered_at']);
        });
    }
};
