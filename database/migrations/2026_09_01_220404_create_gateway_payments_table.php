<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los pagos que la pasarela dice haber cobrado, tal como ella los reporta.
 *
 * No son ventas. Son el extracto del proveedor: lo que Bold afirma haberle
 * abonado al comercio. Existen porque hay cobros que el POS nunca origino
 * -- el caso concreto es el QR fisico pegado al datafono: el comprador lo
 * escanea y paga sin que nadie toque la caja, asi que no hay `reference`
 * nuestra ni pedido al cual atarlo. Antes esos eventos se descartaban y el
 * comerciante no tenia con que cuadrar.
 *
 * Guardar el extracto aparte, y cruzarlo despues, es lo mismo que hace
 * cualquiera con la conciliacion bancaria: la fuente del banco y la propia
 * se comparan, no se fusionan. Fusionarlas seria dejar que el proveedor
 * cree ventas en el POS.
 *
 * Tabla NUEVA: el monolito no la conoce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider_slug', 32);

            // El id del cobro EN el proveedor. Unico por negocio: el mismo
            // evento puede llegar dos veces (el Core reintenta hasta 3
            // veces) y no puede contarse dos veces al cuadrar.
            $table->string('provider_payment_id', 128);

            $table->decimal('amount', 12, 2);
            // Como pago el comprador segun el proveedor (CARD, NEQUI...).
            // Texto libre: el vocabulario lo define cada pasarela.
            $table->string('payment_method', 40)->nullable();
            // Numero de aprobacion del recibo. Es lo que el comerciante ve
            // en el voucher fisico, asi que es con lo que va a reclamar.
            $table->string('approval_number', 40)->nullable();
            $table->timestamp('occurred_at')->nullable();

            // La venta con la que cuadro, si se encontro. Nulo = el
            // proveedor cobro algo que el POS no tiene registrado, que es
            // justo lo que el comerciante necesita ver.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('matched_at')->nullable();

            // El evento crudo, para diagnosticar sin volver a pedirselo al
            // proveedor (Bold solo conserva 24 horas).
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'provider_slug', 'provider_payment_id'], 'uq_gateway_payment');
            // El cuadre siempre pregunta "que cobro este negocio en esta
            // franja", nunca por id.
            $table->index(['business_id', 'occurred_at'], 'ix_gateway_payments_business_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payments');
    }
};
