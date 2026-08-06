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
            'paid_until' => $business->paid_until?->toDateString(),
            'pricing' => $this->subscriptions->pricingBreakdown($business),
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
