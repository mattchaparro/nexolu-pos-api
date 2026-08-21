<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\SendsReceipts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PayServiceOrderRequest;
use App\Http\Requests\Api\V1\SetServiceOrderStageRequest;
use App\Http\Requests\Api\V1\StoreServiceOrderRequest;
use App\Http\Requests\Api\V1\UpdateServiceOrderRequest;
use App\Http\Resources\Api\V1\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Services\ReceiptPdfService;
use App\Services\ServiceOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServiceOrderController extends Controller
{
    use SendsReceipts;

    public function __construct(private ServiceOrderService $serviceOrderService) {}

    /**
     * Card de saldo pendiente agregado del listado - suma total-amount_paid
     * de TODAS las ordenes pendientes/abonadas del negocio, sin importar
     * los filtros de index() (mismo criterio que ProductController::summary()).
     * Puerto de $pendingBalance en ServiceOrdersController::index() del
     * legacy, que vive junto al listado alla porque Inertia manda todo en
     * una sola respuesta - acá es su propio endpoint, igual que Productos.
     */
    public function summary(): JsonResponse
    {
        $pendingBalance = ServiceOrder::whereIn('status', ['pending', 'partial'])
            ->selectRaw('SUM(total - amount_paid) as pending_balance')
            ->value('pending_balance');

        return response()->json([
            'pending_balance' => (float) ($pendingBalance ?? 0),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ServiceOrder::with(['client', 'items', 'payments', 'stage'])
            ->orderByRaw("FIELD(status, 'partial', 'pending', 'paid', 'cancelled')")
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('stage_id')) {
            $query->where('stage_id', $request->integer('stage_id'));
        }

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('service_name', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to'));
        }

        return ServiceOrderResource::collection($query->paginate(25)->withQueryString());
    }

    public function store(StoreServiceOrderRequest $request): ServiceOrderResource
    {
        $order = $this->serviceOrderService->create($request->user(), $request->validated());

        return new ServiceOrderResource($order);
    }

    public function show(ServiceOrder $serviceOrder): ServiceOrderResource
    {
        return new ServiceOrderResource($serviceOrder->load('client', 'items', 'payments', 'stage'));
    }

    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        $order = $this->serviceOrderService->update($serviceOrder, $request->validated());

        return new ServiceOrderResource($order);
    }

    public function pay(PayServiceOrderRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        $order = $this->serviceOrderService->pay(
            $request->user(),
            $serviceOrder,
            (float) $request->validated('amount'),
            $request->validated('payment_method'),
            $request->validated('notes')
        );

        return new ServiceOrderResource($order);
    }

    public function cancel(Request $request, ServiceOrder $serviceOrder): Response
    {
        $this->serviceOrderService->cancel($request->user(), $serviceOrder);

        return response()->noContent();
    }

    public function destroy(Request $request, ServiceOrder $serviceOrder): Response
    {
        $this->serviceOrderService->delete($request->user(), $serviceOrder);

        return response()->noContent();
    }

    public function setStage(SetServiceOrderStageRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        $order = $this->serviceOrderService->setStage(
            $request->user(), $serviceOrder, (int) $request->validated('stage_id')
        );

        return new ServiceOrderResource($order);
    }

    public function receipt(ServiceOrder $serviceOrder): Response
    {
        return $this->receiptDownloadResponse(app(ReceiptPdfService::class)->forServiceOrder($serviceOrder));
    }

    public function printReceipt(Request $request, ServiceOrder $serviceOrder): Response
    {
        return $this->receiptPrintResponse(app(ReceiptPdfService::class)->printServiceOrder($serviceOrder, $request->boolean('auto_print', true)));
    }

    public function sendReceipt(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        return $this->receiptSendResponse($request, 'service-order', $serviceOrder->id, 'Comprobante de orden de servicio');
    }
}
