<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InitiateSubscriptionCheckoutRequest;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function status(Request $request): JsonResponse
    {
        $business = $request->user()->business->fresh();

        return response()->json([
            'status' => $business->subscriptionStatus(),
            'days_remaining' => $business->daysRemaining(),
            'trial_ends_at' => $business->trial_ends_at?->toDateString(),
            'paid_until' => $business->paid_until?->toDateString(),
            'pricing' => $this->subscriptions->pricingBreakdown($business),
            'payments' => $business->saasSubscriptionPayments()
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get()
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount_cop' => $payment->amount_cop,
                    'period_label' => $payment->period_label,
                    'paid_at' => $payment->paid_at?->toDateString(),
                    'payment_method' => $payment->payment_method,
                    'days_granted' => $payment->days_granted,
                ]),
        ]);
    }

    public function initiate(InitiateSubscriptionCheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->subscriptions->initiateCheckout(
                $request->user()->business,
                $request->user(),
                $request->validated('redirect_url'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result, 201);
    }

    public function checkoutStatus(Request $request, string $reference): JsonResponse
    {
        return response()->json(
            $this->subscriptions->checkoutStatus($request->user()->business, $reference)
        );
    }
}
