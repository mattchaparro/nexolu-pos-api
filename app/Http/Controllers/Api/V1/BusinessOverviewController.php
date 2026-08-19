<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\BusinessOverviewService;
use Illuminate\Http\Request;

class BusinessOverviewController extends Controller
{
    public function __construct(private BusinessOverviewService $service) {}

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $business = Business::findOrFail($request->user()->business_id);

        $year = $request->integer('year', now()->year);
        $month = max(1, min(12, $request->integer('month', now()->month)));
        $canViewProfit = $request->user()->hasBusinessPermission('accounting.manage');

        return $this->service->overview($business, $year, $month, $canViewProfit);
    }
}
