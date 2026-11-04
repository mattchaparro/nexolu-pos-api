<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correccion de datos puntual (Central Cell, negocio 29), pedida por
 * Alejandro el 2026-11-04 al pasar el negocio al modo "on_sale".
 *
 * Los creditos con financiadoras que estaban pendientes de registrar el giro
 * pasan a darse por recibidos el dia de su venta, con el medio
 * "financiado" (se muestra como "Financiadoras"), igual que una venta nueva
 * en ese modo. Asi el dueño no tiene que marcarlos uno por uno.
 *
 * Los cierres de caja ya hechos no cambian: guardan sus totales, y lo
 * financiado nunca fue efectivo, asi que el efectivo esperado tampoco.
 *
 * Idempotente: solo toca creditos que siguen pendientes.
 */
return new class extends Migration
{
    private const BUSINESS_ID = 29;

    private const NOTE = 'Dado por recibido al vender (modo Financiadoras, nov-2026).';

    public function up(): void
    {
        $credits = DB::table('financing_credits')
            ->join('sales', 'sales.id', '=', 'financing_credits.sale_id')
            ->where('financing_credits.business_id', self::BUSINESS_ID)
            ->where('financing_credits.status', 'pending')
            ->select('financing_credits.id', 'sales.closed_at', 'sales.created_at', 'sales.user_id')
            ->get();

        foreach ($credits as $credit) {
            DB::table('financing_credits')->where('id', $credit->id)->where('status', 'pending')->update([
                'status' => 'paid',
                'paid_at' => $credit->closed_at ?? $credit->created_at,
                'payout_method' => 'financiado',
                'received_by_user_id' => $credit->user_id,
                'notes' => self::NOTE,
                'updated_at' => now(),
            ]);
        }

        DB::table('businesses')->where('id', self::BUSINESS_ID)->update(['financing_payout_mode' => 'on_sale']);
    }

    /** Vuelve a pendiente solo lo que paso esta migracion. */
    public function down(): void
    {
        DB::table('financing_credits')
            ->where('business_id', self::BUSINESS_ID)
            ->where('status', 'paid')
            ->where('notes', self::NOTE)
            ->update([
                'status' => 'pending',
                'paid_at' => null,
                'payout_method' => null,
                'received_by_user_id' => null,
                'notes' => null,
                'updated_at' => now(),
            ]);
    }
};
