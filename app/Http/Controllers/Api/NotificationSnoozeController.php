<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Silenciar la alerta de inventario bajo desde el enlace del correo, sin
 * login: la firma de la URL (middleware `signed`) es la unica autenticacion
 * - la misma logica que un enlace de "darse de baja". A diferencia de
 * legacy, que mostraba un formulario (GET) y aplicaba el cambio en un paso
 * aparte (POST), acá el correo ya trae un enlace por cada opcion de dias:
 * un solo clic, sin formulario intermedio - no hay forma de que un cliente
 * de correo dispare un POST desde un enlace de todos modos.
 */
class NotificationSnoozeController extends Controller
{
    const DAY_OPTIONS = [3, 7, 15, 30];

    public function snooze(Request $request, Business $business): View
    {
        $days = $request->integer('days');
        abort_unless(in_array($days, self::DAY_OPTIONS, true), 422, 'Opcion de dias invalida.');

        $until = now()->addDays($days);
        $business->update(['low_stock_snoozed_until' => $until]);

        return view('notifications.low-stock-snoozed', [
            'business_name' => $business->name,
            'until' => $until->timezone('America/Bogota')->format('d/m/Y'),
            'days' => $days,
        ]);
    }
}
