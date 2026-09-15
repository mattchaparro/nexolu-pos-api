<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El interruptor por producto del catalogo de WhatsApp (fase 4 del plan de
 * Nexolu Connect): que se publica y que no lo decide el negocio producto a
 * producto, no un volcado de todo el inventario. Aditiva y con default -
 * el monolito legacy que comparte esta tabla ni la ve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('available_on_whatsapp')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_on_whatsapp');
        });
    }
};
