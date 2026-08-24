<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            ...$this->dashboard->todaySummary($request->user()->business),
            // dashboard_shortcuts, no la resolucion completa contra el menu:
            // el frontend ya resuelve {route_name, color} contra su propia
            // lista de nav items filtrada por permiso (useNavItems()), igual
            // que el legacy la resolvia contra admin.json - null significa
            // "el usuario no personalizo todavia, usar el default calculado
            // en el cliente".
            'shortcuts' => $request->user()->dashboard_shortcuts,
        ]);
    }

    public function updateShortcuts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shortcuts' => ['required', 'array', 'max:20'],
            'shortcuts.*.route_name' => ['required', 'string', 'max:100'],
            'shortcuts.*.color' => ['required', 'string', 'in:primary,outline'],
        ]);

        $request->user()->update(['dashboard_shortcuts' => $validated['shortcuts']]);

        return response()->json(['shortcuts' => $validated['shortcuts']]);
    }

    public function whatsappOnboarding(Request $request): JsonResponse
    {
        // ->setData(): ver la misma nota en BusinessServiceWorkflowController::show()
        // sobre por que null tiene que emitirse asi para llegar como JSON
        // null real, no "{}".
        return response()->json()->setData($this->dashboard->whatsappOnboarding($request->user()));
    }

    public function dismissWhatsappOnboarding(Request $request): JsonResponse
    {
        $request->user()->forceFill(['whatsapp_onboarding_dismissed_at' => now()])->save();

        return response()->json(['ok' => true]);
    }
}
