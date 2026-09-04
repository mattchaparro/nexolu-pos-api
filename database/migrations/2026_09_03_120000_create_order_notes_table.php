<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas de un pedido: las del equipo y las que se le mandan al comprador.
 *
 * Las dos en la misma tabla porque son lo mismo -- algo que alguien escribio
 * sobre este pedido, en un momento -- y separarlas obligaria a intercalar dos
 * listados para leer la conversacion completa. Lo que cambia es `visibility`.
 *
 * `order_status_history.note` no alcanza: esa nota viaja pegada a un cambio
 * de estado, y la mitad de lo que hay que anotar ("el cliente pidio que se lo
 * dejen con el portero") no cambia ningun estado.
 *
 * Una nota al comprador guarda por donde salio Y como le fue: WhatsApp con
 * texto libre solo se entrega dentro de la ventana de 24 horas de Meta, asi
 * que el envio falla de verdad y el comerciante tiene que poder verlo. Sin
 * `delivery`, "le escribi" y "crei que le escribi" se ven igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Nulo cuando la escribio el sistema.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // internal = solo la ve el equipo. customer = se le mando al comprador.
            $table->string('visibility', 12)->default('internal');
            $table->text('body');

            // Por donde se mando y que dijo cada canal. Vacios en una nota interna.
            $table->json('channels')->nullable();
            $table->json('delivery')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
    }
};
