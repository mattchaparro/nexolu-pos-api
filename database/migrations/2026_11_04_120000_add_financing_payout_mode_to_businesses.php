<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Como se da por recibido lo que financia un tercero (Addi, Banti...):
//   on_sale -> el mismo dia de la venta, como medio "Financiadoras" (no hay
//              que registrar el giro despues; la caja ya cuadra ese dia).
//   manual  -> queda pendiente y el dueño registra cada giro cuando llega.
// Por defecto on_sale: es lo que pidio Central Cell y el que menos trabajo da.
// Columna aditiva con default: el legacy ya no escribe esta tabla.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('financing_payout_mode', 20)->default('on_sale');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('financing_payout_mode');
        });
    }
};
