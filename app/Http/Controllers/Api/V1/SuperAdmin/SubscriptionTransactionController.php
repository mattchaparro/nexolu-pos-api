<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SuperAdmin\SubscriptionCheckoutOrderResource;
use App\Models\SubscriptionCheckoutOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionTransactionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $transactions = SubscriptionCheckoutOrder::with('business')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return SubscriptionCheckoutOrderResource::collection($transactions);
    }
}
