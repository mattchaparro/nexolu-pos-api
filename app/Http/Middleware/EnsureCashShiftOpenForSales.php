<?php

namespace App\Http\Middleware;

use App\Models\CashShift;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerto de la regla del legacy (routes/employee.php: middleware
 * 'cash_shift.sales' sobre POST /sales): un empleado que maneja caja no debe
 * poder vender sin haber abierto su turno - si no, nadie es responsable del
 * efectivo que entra. Solo aplica a quien tiene cash_shift.manage (el resto
 * de roles no maneja turno) y solo si el negocio tiene el modulo cash_closing
 * habilitado. El dueño/admin queda exento: el legacy asume que el turno es un
 * control sobre el cajero, no sobre quien ya es responsable del negocio.
 */
class EnsureCashShiftOpenForSales
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->can('cash_shift.manage')) {
            return $next($request);
        }

        if (! ($user->business?->hasFeature('cash_closing') ?? false)) {
            return $next($request);
        }

        if ($user->hasRole('admin')) {
            return $next($request);
        }

        $openShift = CashShift::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $openShift) {
            abort(422, 'Abre tu turno de caja antes de registrar ventas.');
        }

        // Turno arrastrado de un dia anterior: sus totales abarcarian dos
        // dias y el cierre de caja de ayer los truncaria. Bloquear vender es
        // lo mas disruptivo que existe, asi que va detras de un flag apagado
        // por defecto (ver BusinessFeaturePresets::catalog) - sin el flag el
        // cajero solo ve el aviso en la pantalla de turno, nunca aqui.
        if ($openShift->isFromAPreviousDay() && $user->business->hasFeature('shift_daily_close_required')) {
            abort(422, 'Tienes un turno abierto desde el '.$openShift->opened_at->format('d/m').'. Ciérralo y abre uno nuevo para poder vender hoy.');
        }

        return $next($request);
    }
}
