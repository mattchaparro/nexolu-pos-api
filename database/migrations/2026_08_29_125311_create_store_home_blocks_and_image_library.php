<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El home de la tienda pasa de tres ranuras fijas a una LISTA de bloques.
 *
 * Antes eran `hero_*`, `trust_*` y `story_*`: siempre esos tres, siempre en
 * ese orden. Servia para arrancar, pero hacia que todas las tiendas Nexolu
 * se vieran iguales, que es exactamente lo que un comerciante nota primero.
 *
 * `home_blocks` es un array ordenado de bloques tipados. Se queda en JSON
 * tipado y NO en HTML a proposito: el HTML libre obliga a sanear, a un CSP
 * de emergencia, y hace que el tema del comercio deje de aplicar sobre lo
 * que el comerciante escribio. Con bloques, el tema siempre gana.
 *
 * `business_store_images` existe porque un bloque repetible no puede usar
 * ranuras fijas: si hay tres galerias, no hay un `gallery_image_path` que
 * alcance. Los bloques referencian imagenes por id.
 *
 * Las columnas viejas NO se borran en esta migracion: primero se migran los
 * datos (abajo), y su eliminacion queda para cuando ya no las lea nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->json('home_blocks')->nullable()->after('font_preset');
        });

        Schema::create('business_store_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            // Texto alternativo: lo escribe el comerciante y lo lee un lector
            // de pantalla o Google cuando la imagen no carga.
            $table->string('alt')->nullable();
            $table->timestamps();

            $table->index('business_id');
        });

        $this->migrateExistingBlocks();
    }

    /**
     * Pasa hero/trust/story a la lista, respetando el orden en que se
     * pintaban. Un negocio que ya armo su home no puede perderla porque
     * cambiamos la forma de guardarla.
     */
    private function migrateExistingBlocks(): void
    {
        foreach (DB::table('business_store_settings')->get() as $row) {
            $blocks = [];

            if ($row->hero_enabled) {
                $blocks[] = array_filter([
                    'id' => 'blk_hero', 'type' => 'hero', 'enabled' => true,
                    'eyebrow' => $row->hero_eyebrow, 'title' => $row->hero_title,
                    'highlight' => $row->hero_highlight, 'subtitle' => $row->hero_subtitle,
                    'cta_label' => $row->hero_cta_label, 'image_path' => $row->hero_image_path,
                ], fn ($v) => $v !== null);
            }

            if ($row->trust_enabled) {
                $blocks[] = [
                    'id' => 'blk_trust', 'type' => 'trust', 'enabled' => true,
                    'items' => json_decode((string) $row->trust_items, true) ?: [],
                ];
            }

            if ($row->story_enabled) {
                $blocks[] = array_filter([
                    'id' => 'blk_story', 'type' => 'story', 'enabled' => true,
                    'eyebrow' => $row->story_eyebrow, 'title' => $row->story_title,
                    'body' => $row->story_body, 'image_path' => $row->story_image_path,
                    'stats' => json_decode((string) $row->story_stats, true) ?: [],
                ], fn ($v) => $v !== null && $v !== []);
            }

            if ($blocks !== []) {
                DB::table('business_store_settings')->where('id', $row->id)
                    ->update(['home_blocks' => json_encode($blocks)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_store_images');
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('home_blocks');
        });
    }
};
