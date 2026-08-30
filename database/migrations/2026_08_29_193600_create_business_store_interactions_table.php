<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clics en los enlaces de contacto de la tienda.
 *
 * Existe porque el boton de WhatsApp deja de apuntar a wa.me y pasa por la
 * API: sin eso, la unica forma de saber si alguien escribe desde la tienda
 * es preguntarle al comerciante.
 *
 * NO se guarda IP ni user agent. Para "cuanta gente escribe desde mi
 * tienda" alcanza con la cuenta y el contexto, y un dato personal que no se
 * usa es un dato que solo agrega obligaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_store_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // whatsapp por ahora; queda abierto para instagram, mapa, etc.
            $table->string('type', 32);
            // De donde salio el clic: 'home', 'product:12', 'order'. Sirve
            // para saber si escriben desde la ficha o desde la portada.
            $table->string('context', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_store_interactions');
    }
};
