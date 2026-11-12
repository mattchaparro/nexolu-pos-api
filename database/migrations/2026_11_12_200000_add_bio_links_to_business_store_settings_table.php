<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Enlaces propios de la página de enlaces de la tienda (/{slug}/enlaces), la
// que el comerciante pone en la bio de Instagram o TikTok: TikTok, Rappi,
// YouTube, un formulario... Lista de {label, url}. La tienda, el WhatsApp,
// Instagram, Facebook y la dirección ya salen de sus propios campos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->json('bio_links')->nullable()->after('facebook_url');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('bio_links');
        });
    }
};
