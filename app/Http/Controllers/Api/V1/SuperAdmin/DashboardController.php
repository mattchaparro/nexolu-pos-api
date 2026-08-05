<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Sale;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SuperAdmin\PlatformFinanceService;

class DashboardController extends Controller
{
    public function __construct(private PlatformFinanceService $finance) {}

    public function index(): array
    {
        $totalBusinesses = Business::count();
        $trialCount = Business::where('trial_ends_at', '>', now())->whereNull('paid_until')->count();
        $paidCount = Business::where('paid_until', '>', now())->count();
        $expiredCount = $totalBusinesses - $trialCount - $paidCount;

        $stats = [
            'total_businesses' => $totalBusinesses,
            'trial' => $trialCount,
            'paid' => $paidCount,
            'expired' => $expiredCount,
            'monthly_revenue_cop' => $this->finance->currentMonthIncomeCop(),
            'mrr_cop' => $this->finance->realMonthlyRecurringRevenueCop(),
            'total_users' => User::count(),
            'new_businesses_last_30_days' => Business::where('created_at', '>=', now()->subDays(30))->count(),
            'open_support_tickets' => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
            'closed_sales_last_30_days' => Sale::where('status', 'closed')->where('closed_at', '>=', now()->subDays(30))->count(),
        ];

        $expiringBusinesses = Business::where(function ($q) {
            $q->where(function ($q2) {
                $q2->whereNotNull('paid_until')->where('paid_until', '>', now())->where('paid_until', '<=', now()->addDays(7));
            })->orWhere(function ($q2) {
                $q2->whereNull('paid_until')->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now())->where('trial_ends_at', '<=', now()->addDays(7));
            });
        })
            ->orderByRaw('COALESCE(paid_until, trial_ends_at) ASC')
            ->limit(10)
            ->get()
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'owner_name' => $b->owner_name,
                'status' => $b->subscriptionStatus(),
                'days_remaining' => $b->daysRemaining(),
            ]);

        $topBusinesses = Business::withCount(['sales', 'products', 'users'])
            ->orderByDesc('sales_count')
            ->limit(10)
            ->get()
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'sales_count' => $b->sales_count,
                'products_count' => $b->products_count,
                'users_count' => $b->users_count,
                'status' => $b->subscriptionStatus(),
            ]);

        return [
            'stats' => $stats,
            'expiring_businesses' => $expiringBusinesses,
            'top_businesses' => $topBusinesses,
        ];
    }
}
