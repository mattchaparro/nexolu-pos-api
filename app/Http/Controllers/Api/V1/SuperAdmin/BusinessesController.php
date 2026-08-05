<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\ActivateBusinessRequest;
use App\Http\Requests\Api\V1\SuperAdmin\ChangePlanRequest;
use App\Http\Requests\Api\V1\SuperAdmin\ExtendTrialRequest;
use App\Http\Requests\Api\V1\SuperAdmin\SetCustomPriceRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreBusinessRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreSubscriptionPaymentRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateBusinessConfigRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateBusinessRequest;
use App\Http\Resources\Api\V1\SuperAdmin\BusinessResource;
use App\Http\Resources\Api\V1\SuperAdmin\SaasSubscriptionPaymentResource;
use App\Models\Business;
use App\Models\LogAction;
use App\Models\SaasSubscriptionPayment;
use App\Models\Sale;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\BusinessRegistrationService;
use App\Services\SuperAdmin\SuperAdminBusinessService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class BusinessesController extends Controller
{
    public function __construct(
        private BusinessRegistrationService $registrationService,
        private SuperAdminBusinessService $businessService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Business::withCount('users');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->string('status')->toString()) {
                'trial' => $query->where('trial_ends_at', '>', now())->whereNull('paid_until'),
                'paid' => $query->where('paid_until', '>', now()),
                'inactive' => $query->where('active', false),
                'expired' => $query->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNull('paid_until')->where(function ($q3) {
                            $q3->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now());
                        });
                    })->orWhere(function ($q2) {
                        $q2->whereNotNull('paid_until')->where('paid_until', '<=', now());
                    });
                }),
                default => null,
            };
        }

        $businesses = $query->latest()->paginate(20)->withQueryString();
        $this->attachAdminUsers($businesses->getCollection());
        $this->attachLastActivity($businesses->getCollection());

        return BusinessResource::collection($businesses);
    }

    public function show(Business $business): array
    {
        $business->loadCount(['users', 'products', 'sales', 'productCategories']);
        $this->attachAdminUsers(collect([$business]));
        $this->attachLastActivity(collect([$business]));

        $salesLast30 = Sale::where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->subDays(30));

        $stats = [
            'revenue_last_30_days' => (float) (clone $salesLast30)->where('is_non_revenue', false)->sum('total'),
            'closed_sales_last_30_days' => (int) (clone $salesLast30)->count(),
            'avg_ticket_last_30_days' => (float) ((clone $salesLast30)->avg('total') ?? 0),
            'active_sellers_last_30_days' => (int) (clone $salesLast30)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            'open_sales_now' => (int) Sale::where('business_id', $business->id)->where('status', 'open')->count(),
            'last_sale_at' => Sale::where('business_id', $business->id)->latest('created_at')->value('created_at'),
        ];

        $teamUsers = User::where('business_id', $business->id)
            ->with('roles:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $rolesSummary = $teamUsers
            ->flatMap(function (User $user) {
                if ($user->roles->isEmpty()) {
                    return [['name' => 'sin_rol', 'is_active' => (bool) $user->is_active]];
                }

                return $user->roles->map(fn ($role) => ['name' => $role->name, 'is_active' => (bool) $user->is_active]);
            })
            ->groupBy('name')
            ->map(fn ($group, $role) => [
                'role' => $role,
                'total' => $group->count(),
                'active' => collect($group)->where('is_active', true)->count(),
            ])
            ->values();

        // El equipo completo, no solo el resumen por rol: es lo que permite ver
        // quien de verdad entra al sistema (last_active_at) y quien nunca lo usa.
        $team = $teamUsers->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'is_business_owner' => (bool) $user->is_business_owner,
            'roles' => $user->roles->pluck('name'),
            'last_active_at' => $user->lastActiveAt()?->toIso8601String(),
        ]);

        $recentPayments = $business->saasSubscriptionPayments()
            ->with('recordedBy')
            ->latest('paid_at')
            ->latest('id')
            ->limit(5)
            ->get();

        $stats['open_support_tickets'] = SupportTicket::where('business_id', $business->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        return [
            'business' => new BusinessResource($business),
            'stats' => $stats,
            'team' => $team,
            'roles_summary' => $rolesSummary,
            'recent_subscription_payments' => SaasSubscriptionPaymentResource::collection($recentPayments),
        ];
    }

    public function store(StoreBusinessRequest $request): BusinessResource
    {
        $data = $this->registrationService->register($request->validated());

        AuditLogger::log('superadmin.business.created', ['business_id' => $data['business']->id]);

        return new BusinessResource($data['business']);
    }

    public function update(UpdateBusinessRequest $request, Business $business): BusinessResource
    {
        $data = $request->validated();

        $business->update([
            'name' => $data['business_name'],
            'owner_name' => $data['owner_name'],
            'phone' => $data['phone'] ?? null,
            'nit' => $data['nit'] ?? null,
            'address' => $data['address'] ?? null,
            'crm_notes' => $data['crm_notes'] ?? null,
            'crm_next_follow_up_at' => $data['crm_next_follow_up_at'] ?? null,
        ]);

        return new BusinessResource($business->fresh());
    }

    public function destroy(Business $business): Response
    {
        User::where('business_id', $business->id)->update(['is_active' => false]);
        $business->delete();

        AuditLogger::log('superadmin.business.deleted', ['business_id' => $business->id]);

        return response()->noContent();
    }

    public function toggle(Business $business): BusinessResource
    {
        $business->update(['active' => ! $business->active]);

        AuditLogger::log('superadmin.business.toggled', ['business_id' => $business->id, 'active' => $business->active]);

        return new BusinessResource($business);
    }

    public function activate(ActivateBusinessRequest $request, Business $business): SaasSubscriptionPaymentResource
    {
        $payment = $this->businessService->activate($business, $request->user(), $request->validated());

        AuditLogger::log('superadmin.business.activated', [
            'business_id' => $business->id,
            'days' => $request->validated('days'),
            'amount_cop' => $payment->amount_cop,
        ]);

        return new SaasSubscriptionPaymentResource($payment);
    }

    public function extendTrial(ExtendTrialRequest $request, Business $business): BusinessResource
    {
        $business->extendTrial((int) $request->validated('days'));

        AuditLogger::log('superadmin.business.trial_extended', ['business_id' => $business->id, 'days' => $request->validated('days')]);

        return new BusinessResource($business);
    }

    public function setCustomPrice(SetCustomPriceRequest $request, Business $business): BusinessResource
    {
        $business->update(['custom_price_cop' => $request->validated('custom_price_cop') ?: null]);

        return new BusinessResource($business);
    }

    public function changePlan(ChangePlanRequest $request, Business $business): BusinessResource
    {
        $this->businessService->changePlan($business, $request->validated('plan'));

        AuditLogger::log('superadmin.business.plan_changed', ['business_id' => $business->id, 'plan' => $request->validated('plan')]);

        return new BusinessResource($business);
    }

    public function updateConfig(UpdateBusinessConfigRequest $request, Business $business): BusinessResource
    {
        $this->businessService->updateConfig($business, $request->validated('subscription_plan'), $request->validated('feature_flags'));

        return new BusinessResource($business);
    }

    public function subscriptionPayments(Business $business): AnonymousResourceCollection
    {
        $payments = $business->saasSubscriptionPayments()
            ->with('recordedBy')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return SaasSubscriptionPaymentResource::collection($payments);
    }

    public function storeSubscriptionPayment(StoreSubscriptionPaymentRequest $request, Business $business): SaasSubscriptionPaymentResource
    {
        $data = $request->validated();

        $payment = SaasSubscriptionPayment::create([
            'business_id' => $business->id,
            'amount_cop' => (int) $data['amount_cop'],
            'period_label' => $data['period_label'],
            'paid_at' => $data['paid_at'],
            'payment_method' => $data['payment_method'] ?? 'manual_retroactive',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'recorded_by_user_id' => $request->user()->id,
        ]);

        return new SaasSubscriptionPaymentResource($payment);
    }

    public function destroySubscriptionPayment(Business $business, SaasSubscriptionPayment $payment): Response
    {
        abort_unless((int) $payment->business_id === (int) $business->id, 404);

        if ($payment->days_granted && $business->paid_until) {
            $business->paid_until = $business->paid_until->subDays($payment->days_granted);
            $business->save();
        }

        $payment->delete();

        return response()->noContent();
    }

    /**
     * @param  Collection<int, Business>  $businesses
     */
    private function attachAdminUsers($businesses): void
    {
        $ids = $businesses->pluck('id');
        $adminRoleId = Role::where('name', 'admin')->where('guard_name', 'web')->value('id');

        $adminMap = $adminRoleId
            ? User::whereIn('business_id', $ids)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $adminRoleId))
                ->get(['id', 'name', 'business_id'])
                ->groupBy('business_id')
            : collect();

        $businesses->each(function (Business $business) use ($adminMap) {
            $admin = $adminMap->get($business->id)?->first();
            $business->admin_user_id = $admin?->id;
            $business->admin_user_name = $admin?->name;
        });
    }

    /**
     * @param  Collection<int, Business>  $businesses
     */
    private function attachLastActivity($businesses): void
    {
        $ids = $businesses->pluck('id');

        $lastActivityMap = LogAction::whereIn('business_id', $ids)
            ->selectRaw('business_id, max(created_at) as last_at')
            ->groupBy('business_id')
            ->pluck('last_at', 'business_id');

        $businesses->each(function (Business $business) use ($lastActivityMap) {
            $business->last_activity_at = $lastActivityMap[$business->id] ?? null;
        });
    }
}
