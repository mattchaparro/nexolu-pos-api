<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseOpenTabRequest;
use App\Http\Requests\Api\V1\OpenTabItemsRequest;
use App\Http\Requests\Api\V1\RecordPartialPaymentRequest;
use App\Http\Requests\Api\V1\StoreOpenTabRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Models\Sale;
use App\Services\OpenTabService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OpenTabController extends Controller
{
    public function __construct(private OpenTabService $openTabService) {}

    public function index(): AnonymousResourceCollection
    {
        return SaleResource::collection(
            Sale::where('status', 'open')
                ->with([...self::itemRelations(), 'partialPayments', 'table'])
                ->latest()
                ->get()
        );
    }

    /**
     * Lo que SaleResource necesita de cada linea. El producto se carga con
     * lo que ProductResource consulta por fila (si tiene variantes, precio y
     * stock de la sede): esta lista se pide cada vez que cambia una cuenta,
     * y con una consulta por item tardaba de 1,5 a 4 s en un restaurante con
     * varias mesas abiertas (Sentry, oct-2026).
     *
     * @return array<int|string, mixed>
     */
    private static function itemRelations(): array
    {
        return [
            'items.product' => fn ($query) => $query->withExists('variants')->withBranchStock()->withBranchPrice(),
            'items.options',
            'items.productVariant.attributeValues.productAttribute',
        ];
    }

    public function store(StoreOpenTabRequest $request): SaleResource
    {
        $sale = $this->openTabService->openTab($request->user(), $request->validated());

        // customer_name viaja en el detalle a proposito: si la cuenta se
        // cancela despues (borrado en duro), la auditoria es lo UNICO que
        // recuerda de quien era - sin esto, "¿de quien era la cuenta
        // borrada?" quedo sin respuesta en un caso real (2026-09-03).
        AuditLogger::log('tab.opened', ['sale_id' => $sale->id, 'table_id' => $sale->table_id, 'customer_name' => $sale->customer_name]);

        return new SaleResource($sale);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load([...self::itemRelations(), 'partialPayments', 'paymentSplits', 'table']));
    }

    public function addItems(OpenTabItemsRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->addItems($request->user(), $sale, $request->validated('items'));

        AuditLogger::log('tab.items_added', ['sale_id' => $sale->id, 'items' => $request->validated('items')]);

        return new SaleResource($sale);
    }

    public function syncItems(OpenTabItemsRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->syncItems($request->user(), $sale, $request->validated('items'));

        AuditLogger::log('tab.items_synced', ['sale_id' => $sale->id, 'items' => $request->validated('items'), 'total' => $sale->total]);

        return new SaleResource($sale);
    }

    public function recordPartialPayment(RecordPartialPaymentRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->recordPartialPayment(
            $request->user(),
            $sale,
            (float) $request->validated('amount'),
            $request->validated('payment_method'),
            $request->validated('payer_label'),
        );

        AuditLogger::log('tab.partial_payment', [
            'sale_id' => $sale->id,
            'amount' => $request->validated('amount'),
            'payment_method' => $request->validated('payment_method'),
        ]);

        return new SaleResource($sale->load('items.product', 'items.options', 'items.productVariant.attributeValues.productAttribute', 'partialPayments', 'paymentSplits'));
    }

    public function close(CloseOpenTabRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->close($request->user(), $sale, $request->validated());

        AuditLogger::log('tab.closed', ['sale_id' => $sale->id, 'payment_method' => $sale->payment_method]);

        return new SaleResource($sale);
    }

    public function destroy(Request $request, Sale $sale): Response
    {
        AuditLogger::log('tab.cancelled', [
            'sale_id' => $sale->id,
            'table_id' => $sale->table_id,
            'customer_name' => $sale->customer_name,
            'total' => $sale->total,
        ]);

        $this->openTabService->cancelOpenTab($request->user(), $sale);

        return response()->noContent();
    }
}
