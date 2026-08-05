<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PlatformFinanceService;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(private PlatformFinanceService $finance) {}

    public function index(Request $request): array
    {
        $year = $request->integer('year', now()->year);
        $month = $request->integer('month', now()->month);

        return [
            'summary' => $this->finance->monthlySummary($year, $month),
            'mrr_cop' => $this->finance->realMonthlyRecurringRevenueCop(),
        ];
    }
}
