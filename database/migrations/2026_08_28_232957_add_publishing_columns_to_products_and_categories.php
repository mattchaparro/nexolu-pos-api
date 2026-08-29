<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Que se publica en la tienda online y en que orden.
 *
 * ALTER sobre dos tablas que el monolito legacy tambien lee y escribe, asi
 * que aplica la regla de CLAUDE.md. Auditoria hecha antes de escribir esto:
 *
 * - No hay un solo INSERT posicional ni SQL crudo contra `products` o
 *   `product_categories` en pos-saas (grep de `insert into`, `DB::insert`,
 *   `DB::statement`, `::insert(`): todo pasa por Eloquent.
 * - Ambos modelos del legacy declaran `$fillable`, no `$guarded`, asi que
 *   una columna que no conocen no puede colarse ni romperles un mass assign.
 * - BusinessDataExporter copia filas completas de forma dinamica
 *   (discoverClosure), sin listas de columnas escritas a mano.
 *
 * Todas son aditivas y con default, de modo que el legacy sigue insertando
 * sin nombrarlas. `is_published` arranca en false: publicar es una decision
 * explicita del comerciante - un catalogo entero expuesto de golpe incluiria
 * insumos, servicios internos y productos sin foto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('is_active');
            // Descripcion larga para la ficha publica, separada de
            // `description` (que es la nota corta que ve el cajero).
            $table->text('online_description')->nullable()->after('is_published');

            $table->index(['business_id', 'is_published']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('icon');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_published']);
            $table->dropColumn(['is_published', 'online_description']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn(['is_published', 'sort_order']);
        });
    }
};
