<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un cupon es un descuento que el COMPRADOR redime escribiendo un codigo.
 *
 * Se modela sobre `discounts` en vez de una tabla nueva porque es lo mismo
 * en esencia -- un porcentaje o un monto que se resta -- y ya existe
 * `Discount::computeAmount()` con esa aritmetica probada. Lo que un cupon
 * agrega es solo la parte que un descuento del mostrador no necesita: un
 * codigo que alguien teclea, una vigencia y un tope de usos.
 *
 * `code IS NULL` = descuento del mostrador, como los de siempre. Con codigo
 * = cupon de la tienda. Los del mostrador no cambian en nada.
 *
 * `discounts` es tabla COMPARTIDA con el monolito. Auditado antes de
 * escribir esto: el legacy la usa con `Discount::create($data)` sobre un
 * `$fillable` por nombre (Admin/DiscountsController.php:42) y no hay un solo
 * INSERT posicional ni `SELECT *` con recuento de columnas. Columnas
 * nullable que no conoce son invisibles para el.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            // Se guarda en mayusculas y se compara en mayusculas: nadie
            // escribe un cupon respetando el uso de mayusculas del volante.
            $table->string('code', 40)->nullable()->after('name');

            $table->timestamp('starts_at')->nullable()->after('is_active');
            $table->timestamp('ends_at')->nullable()->after('starts_at');

            // Tope total de redenciones. Nulo = sin tope.
            $table->unsignedInteger('max_uses')->nullable()->after('ends_at');
            $table->unsignedInteger('used_count')->default(0)->after('max_uses');

            // Compra minima para que aplique.
            $table->decimal('min_order_amount', 12, 2)->nullable()->after('used_count');

            // Dos cupones con el mismo codigo en el mismo negocio harian que
            // "cual aplica" dependa del orden de la tabla.
            $table->unique(['business_id', 'code'], 'uq_discount_business_code');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropUnique('uq_discount_business_code');
            $table->dropColumn([
                'code', 'starts_at', 'ends_at', 'max_uses', 'used_count', 'min_order_amount',
            ]);
        });
    }
};
