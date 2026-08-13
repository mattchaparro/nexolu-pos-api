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
        return response()->json(
            $this->dashboard->todaySummary($request->user()->business)
        );
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
