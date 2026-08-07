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
}
