<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El carrito del comprador, guardado en el servidor.
 *
 * Revierte una decision explicita: hasta hoy el carrito vivia SOLO en el
 * navegador, y el motivo escrito era bueno -- sin cuentas de comprador, una
 * tabla de carritos es basura anonima que nadie limpia. Sigue siendo cierto,
 * y por eso esta tabla se poda sola (ver `PruneAbandonedCartsCommand`).
 *
 * Lo que cambia el balance es que sin carrito en el servidor NO SE PUEDE
 * recuperar uno abandonado: el comercio nunca se entera de que alguien
 * estuvo a punto de comprar. Ese es el unico motivo por el que existe.
 *
 * `contact_*` se llena solo si el comprador lo escribio, y nunca es
 * obligatorio: pedir el correo antes de comprar cuesta conversion, asi que
 * se ofrece y no se exige. Un carrito sin contacto no se puede recuperar --
 * se guarda igual porque sirve para saber CUANTOS se abandonan, que ya es
 * informacion que el comerciante no tenia.
 *
 * Tabla NUEVA: el monolito no la conoce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // La llave que manda el navegador. Es lo unico que identifica a
            // un comprador sin cuenta, asi que va larga y aleatoria: quien
            // adivine un token ve un carrito ajeno.
            $table->string('token', 64)->unique();

            $table->json('items');
            $table->decimal('subtotal', 12, 2)->default(0);

            // Solo si lo dio. Un carrito sin esto no se puede recuperar.
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 160)->nullable();

            // Ultimo toque del comprador: es lo que define "abandonado".
            $table->timestamp('last_activity_at')->nullable();
            // Cuando se le mando el recordatorio. Una sola vez: insistirle a
            // alguien que no compro es la via rapida a que marque spam.
            $table->timestamp('reminded_at')->nullable();
            // El pedido que salio de este carrito, si compro.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // El job pregunta siempre "carritos de este negocio, inactivos
            // desde X, sin recordar y sin pedido".
            $table->index(['business_id', 'last_activity_at'], 'ix_store_carts_business_activity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_carts');
    }
};
