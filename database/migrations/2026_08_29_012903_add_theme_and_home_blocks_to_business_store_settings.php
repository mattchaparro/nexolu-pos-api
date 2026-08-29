<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personalizacion de la tienda: tema y contenido del home.
 *
 * TEMA POR SEMILLAS. Se guardan tres colores, no una paleta completa: marca
 * (acciones), superficie (fondo) y acento (destacados). El resto de tonos
 * -texto, bordes, superficies secundarias, color sobre la marca- los deriva
 * el storefront a partir de la luminancia de estas semillas, con contraste
 * garantizado. Guardar los ~10 tonos por separado daria mas control y muchas
 * tiendas ilegibles; la tipografia va por preset curado por la misma razon.
 *
 * HOME POR BLOQUES FIJOS. Un conjunto cerrado de secciones opcionales (hero,
 * franja de servicios, historia) con campos concretos, no un constructor
 * libre: el comerciante llena formularios en vez de armar paginas, y no hay
 * que sostener orden de bloques, previsualizacion ni versionado.
 *
 * Los bloques repetibles (servicios, estadisticas) van en JSON y no en
 * tablas propias: son como mucho tres o cuatro filas que solo se leen
 * enteras junto a la tienda, nunca se consultan ni se filtran por separado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            // --- Tema ---
            // primary_color ya existe y pasa a ser la semilla de marca.
            $table->string('surface_color', 7)->nullable()->after('primary_color');
            $table->string('accent_color', 7)->nullable()->after('surface_color');
            $table->string('font_preset', 20)->default('moderna')->after('accent_color');

            // --- Hero ---
            $table->boolean('hero_enabled')->default(false)->after('font_preset');
            $table->string('hero_eyebrow')->nullable()->after('hero_enabled');
            $table->string('hero_title')->nullable()->after('hero_eyebrow');
            // Fragmento del titular que se resalta (el <em> del mockup).
            $table->string('hero_highlight')->nullable()->after('hero_title');
            $table->text('hero_subtitle')->nullable()->after('hero_highlight');
            $table->string('hero_cta_label')->nullable()->after('hero_subtitle');
            $table->string('hero_image_path')->nullable()->after('hero_cta_label');

            // --- Franja de servicios ---
            // [{icon, title, text}, ...]
            $table->boolean('trust_enabled')->default(false)->after('hero_image_path');
            $table->json('trust_items')->nullable()->after('trust_enabled');

            // --- Historia ---
            $table->boolean('story_enabled')->default(false)->after('trust_items');
            $table->string('story_eyebrow')->nullable()->after('story_enabled');
            $table->string('story_title')->nullable()->after('story_eyebrow');
            $table->text('story_body')->nullable()->after('story_title');
            $table->string('story_image_path')->nullable()->after('story_body');
            // [{value, label}, ...]
            $table->json('story_stats')->nullable()->after('story_image_path');

            // --- Pie ---
            $table->string('address')->nullable()->after('story_stats');
            $table->string('opening_hours')->nullable()->after('address');
            $table->string('instagram_url')->nullable()->after('opening_hours');
            $table->string('facebook_url')->nullable()->after('instagram_url');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn([
                'surface_color', 'accent_color', 'font_preset',
                'hero_enabled', 'hero_eyebrow', 'hero_title', 'hero_highlight',
                'hero_subtitle', 'hero_cta_label', 'hero_image_path',
                'trust_enabled', 'trust_items',
                'story_enabled', 'story_eyebrow', 'story_title', 'story_body',
                'story_image_path', 'story_stats',
                'address', 'opening_hours', 'instagram_url', 'facebook_url',
            ]);
        });
    }
};
