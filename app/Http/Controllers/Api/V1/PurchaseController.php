<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PayPurchaseRequest;
use App\Http\Requests\Api\V1\StorePurchaseRequest;
use App\Http\Resources\Api\V1\PurchaseResource;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\ReminderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseService $purchaseService,
        private ReminderService $reminderService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Purchase::with(['supplier', 'lines', 'payments'])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('purchased_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchased_at', '<=', $request->date('to'));
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->string('payment_status'));
        }

        return PurchaseResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StorePurchaseRequest $request): PurchaseResource
    {
        $data = $request->validated();
        $purchase = $this->purchaseService->registerPurchase($request->user(), $data);

        // El recordatorio de pago solo tiene sentido si de verdad quedo a
        // credito: una compra pagada de una vez no tiene nada pendiente que
        // recordar.
        if (! empty($data['is_credit']) && ! empty($data['payment_reminder_date'])) {
            $supplierName = $purchase->supplier?->name;
            $this->reminderService->create($request->user()->business_id, $request->user()->id, [
                'title' => ($data['payment_reminder_title'] ?? null) ?: ('Pagar compra'.($supplierName ? " a {$supplierName}" : '')),
                'due_date' => $data['payment_reminder_date'],
                'recurrence' => $data['payment_reminder_recurrence'] ?? 'none',
                'end_date' => $data['payment_reminder_end_date'] ?? null,
                'notes' => $data['payment_reminder_notes'] ?? null,
                'remindable_type' => Purchase::class,
                'remindable_id' => $purchase->id,
            ]);
        }

        return new PurchaseResource($purchase);
    }

    public function show(Purchase $purchase): PurchaseResource
    {
        return new PurchaseResource($purchase->load('supplier', 'lines.product', 'lines.productVariant.attributeValues.productAttribute', 'lines.ingredient', 'payments'));
    }

    public function pay(PayPurchaseRequest $request, Purchase $purchase): PurchaseResource
    {
        $this->purchaseService->pay(
            $request->user(),
            $purchase,
            (float) $request->validated('amount'),
            $request->validated('payment_method')
        );

        return new PurchaseResource($purchase->fresh()->load('supplier', 'lines.product', 'lines.productVariant.attributeValues.productAttribute', 'lines.ingredient', 'payments'));
    }
}
