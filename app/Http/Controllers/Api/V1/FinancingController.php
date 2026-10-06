<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FinancingCreditResource;
use App\Http\Resources\Api\V1\FinancingProviderResource;
use App\Models\FinancingCredit;
use App\Models\FinancingProvider;
use App\Services\FinancingService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Financiadoras del negocio y lo que cada una le debe (ventas financiadas por terceros). */
class FinancingController extends Controller
{
    public function __construct(private FinancingService $financingService) {}

    public function providers(Request $request): AnonymousResourceCollection
    {
        $query = FinancingProvider::query()->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return FinancingProviderResource::collection($query->get());
    }

    public function storeProvider(Request $request): FinancingProviderResource
    {
        $provider = FinancingProvider::create($this->validateProvider($request));

        return new FinancingProviderResource($provider);
    }

    public function updateProvider(Request $request, FinancingProvider $provider): FinancingProviderResource
    {
        $provider->update($this->validateProvider($request, $provider));

        return new FinancingProviderResource($provider);
    }

    public function credits(Request $request): AnonymousResourceCollection
    {
        $query = FinancingCredit::with('provider', 'sale', 'receivedBy')->latest();

        if ($request->filled('financing_provider_id')) {
            $query->where('financing_provider_id', $request->integer('financing_provider_id'));
        }

        match ($request->string('status')->toString()) {
            'pending' => $query->where('status', FinancingCredit::STATUS_PENDING),
            'paid' => $query->where('status', FinancingCredit::STATUS_PAID),
            'overdue' => $query->overdue(),
            default => null,
        };

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_phone', 'like', "%{$term}%")
                    ->orWhere('customer_identification', 'like', "%{$term}%")
                    ->orWhere('approval_number', 'like', "%{$term}%");
            });
        }

        return FinancingCreditResource::collection($query->paginate(20)->withQueryString());
    }

    /** Cuanto debe cada financiadora, cuanto esta atrasado y cuanto giro este mes. */
    public function summary(): JsonResponse
    {
        $providers = FinancingProvider::orderBy('name')->get();
        $monthStart = now()->startOfMonth();

        $rows = $providers->map(function (FinancingProvider $provider) use ($monthStart) {
            $pending = FinancingCredit::where('financing_provider_id', $provider->id)->where('status', FinancingCredit::STATUS_PENDING);
            $overdue = FinancingCredit::where('financing_provider_id', $provider->id)->overdue();
            $paidThisMonth = FinancingCredit::where('financing_provider_id', $provider->id)
                ->where('status', FinancingCredit::STATUS_PAID)
                ->where('paid_at', '>=', $monthStart);

            return [
                'id' => $provider->id,
                'name' => $provider->name,
                'is_active' => $provider->is_active,
                'pending_count' => (clone $pending)->count(),
                'pending_amount' => (float) (clone $pending)->sum('amount'),
                'overdue_count' => (clone $overdue)->count(),
                'overdue_amount' => (float) (clone $overdue)->sum('amount'),
                'paid_this_month_amount' => (float) $paidThisMonth->sum('amount'),
            ];
        });

        return response()->json([
            'providers' => $rows->values(),
            'pending_amount' => (float) $rows->sum('pending_amount'),
            'overdue_amount' => (float) $rows->sum('overdue_amount'),
            'paid_this_month_amount' => (float) $rows->sum('paid_this_month_amount'),
        ]);
    }

    public function registerPayout(Request $request, FinancingCredit $credit): FinancingCreditResource
    {
        $data = $request->validate([
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'payout_method' => ['nullable', 'string', 'max:50'],
            'payout_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $credit = $this->financingService->registerPayout($request->user(), $credit, $data);

        AuditLogger::log('financing_credit.paid', [
            'financing_credit_id' => $credit->id,
            'sale_id' => $credit->sale_id,
            'amount' => $credit->amount,
        ]);

        return new FinancingCreditResource($credit);
    }

    public function undoPayout(FinancingCredit $credit): FinancingCreditResource
    {
        $credit = $this->financingService->undoPayout($credit);

        AuditLogger::log('financing_credit.payout_undone', [
            'financing_credit_id' => $credit->id,
            'sale_id' => $credit->sale_id,
        ]);

        return new FinancingCreditResource($credit);
    }

    /** @return array<string, mixed> */
    private function validateProvider(Request $request, ?FinancingProvider $provider = null): array
    {
        $businessId = $request->user()->business_id;

        return $request->validate([
            'name' => [
                $provider ? 'sometimes' : 'required', 'string', 'max:80',
                Rule::unique('financing_providers', 'name')->where('business_id', $businessId)->ignore($provider?->id),
            ],
            'expected_payout_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'Ya tienes una financiadora con ese nombre.',
        ]);
    }
}
