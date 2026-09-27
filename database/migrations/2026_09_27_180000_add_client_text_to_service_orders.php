<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cliente de una orden de servicio pasa a ser texto de la orden, igual
 * que en citas (appointments.client_name/phone/email) y apartados.
 *
 * Hasta ahora la orden solo tenia client_id, asi que escribir un nombre
 * obligaba a convertirlo en una ficha del directorio de clientes: dos
 * "Juan Perez" distintos terminaban compartiendo ficha, y editar el nombre
 * creaba otra. En el mostrador ese dato es de la orden y no se reutiliza.
 * client_id se queda (opcional): las ordenes migradas del legacy y las que
 * nacen de una cita con cliente del directorio lo siguen usando, y el
 * recurso cae a client->name cuando no hay texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('client_name', 150)->nullable()->after('client_id');
            $table->string('client_phone', 30)->nullable()->after('client_name');
            $table->string('client_email', 150)->nullable()->after('client_phone');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'client_phone', 'client_email']);
        });
    }
};
