<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El matching de un pedido de WhatsApp busca al cliente por telefono dentro
 * del negocio; sin indice, cada pedido era un scan de la tabla completa.
 * Aditiva: el legacy no la nota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['business_id', 'phone'], 'clients_business_id_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_business_id_phone_index');
        });
    }
};
