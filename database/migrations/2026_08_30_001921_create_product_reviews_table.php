<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificaciones y comentarios de la tienda online.
 *
 * Tabla NUEVA: el monolito legacy no la conoce ni la va a tocar, asi que no
 * aplica la auditoria de las columnas aditivas.
 *
 * Solo escribe quien compro. `order_id` no es un dato informativo, es la
 * CREDENCIAL: la reseña se crea desde el enlace del pedido (public_token), y
 * el unico modo de reseñar un producto es haberlo llevado en ese pedido. La
 * tienda es una URL publica en internet; un formulario abierto sin esa
 * atadura se llena de spam que nadie limpia.
 *
 * El indice unico (order_id, product_id) hace de esa regla algo que la BASE
 * garantiza y no solo la validacion: una reseña por producto por pedido, sin
 * carrera posible entre dos envios simultaneos del mismo formulario.
 *
 * `status` arranca en 'pending': nada llega a internet sin que el comerciante
 * lo apruebe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();

            // business_id explicito (no derivado del producto) para que el
            // global scope de BelongsToBusiness filtre igual que en el resto
            // del sistema, sin joins.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            // Se copia del pedido al crear la reseña en vez de leerse del
            // pedido al mostrarla: si el comprador luego cambia sus datos, la
            // reseña publicada no debe mutar sola.
            $table->string('author_name');

            $table->enum('status', ['pending', 'approved', 'hidden'])->default('pending');
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['order_id', 'product_id']);
            // El listado publico: aprobadas de un producto, las mas nuevas
            // primero.
            $table->index(['product_id', 'status', 'created_at']);
            // La bandeja de moderacion del comerciante.
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
