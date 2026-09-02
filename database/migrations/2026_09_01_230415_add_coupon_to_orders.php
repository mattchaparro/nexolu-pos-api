<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cupon aplicado a un pedido, congelado en el momento de comprar.
 *
 * Se guarda el CODIGO ademas del id porque un cupon se puede desactivar,
 * vencer o incluso borrar, y el pedido tiene que poder seguir explicando por
 * que costo lo que costo. El monto tambien: si mañana cambia el porcentaje
 * del cupon, el pedido de ayer no puede cambiar de total.
 *
 * `orders` es tabla NUEVA (no la conoce el monolito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->after('shipping_fee')->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable()->after('discount_id');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn(['discount_id', 'coupon_code', 'discount_amount']);
        });
    }
};
