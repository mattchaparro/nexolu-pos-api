<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Página de enlaces del negocio (/{slug}/enlaces), la que va en la bio de
// Instagram o TikTok. Es para TODOS los negocios, tengan o no tienda online:
// - link_page_enabled: el comerciante la publica con un clic. Sin ese paso,
//   cualquier slug respondería y se podría listar quién es cliente de Nexolú.
//   Con la tienda abierta queda publicada igual (la tienda ya es pública).
// - bio_links: sus enlaces propios, lista de {label, url} (TikTok, Rappi...).
//   WhatsApp, Instagram, dirección y la tienda salen de sus propios campos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->boolean('link_page_enabled')->default(false)->after('facebook_url');
            $table->json('bio_links')->nullable()->after('link_page_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn(['link_page_enabled', 'bio_links']);
        });
    }
};
