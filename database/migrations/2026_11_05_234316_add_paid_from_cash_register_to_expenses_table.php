<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Salio de la caja del dia": solo esos gastos se descuentan del efectivo
 * esperado del cierre y del turno. Antes se descontaba todo gasto
 * "operacional", aunque se hubiera pagado por Nequi o de la cuenta del local,
 * y por eso muchos negocios dejaban de registrar gastos: les descuadraba la
 * caja. Los gastos nuevos nacen sin marcar.
 *
 * Los viejos operacionales quedan marcados para que los dias sin cerrar
 * calculen lo mismo que ya venian mostrando (los cierres guardados no cambian:
 * tienen su total congelado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('paid_from_cash_register')->default(false)->after('payment_method');
        });

        DB::table('expenses')->where('scope', 'operacional')->update(['paid_from_cash_register' => true]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('paid_from_cash_register');
        });
    }
};
