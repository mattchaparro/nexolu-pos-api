<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Modo menú: la tienda se ve como un menú digital (categorías, fotos y precios)
// pero sin carrito ni pedidos. Es para el restaurante que quiere un QR en las
// mesas o un enlace para mostrar la carta, y atiende por su cuenta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->boolean('menu_mode')->default(false)->after('pickup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('menu_mode');
        });
    }
};
