<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeliveryResource;
use App\Http\Resources\Api\V1\PrintJobResource;
use App\Models\Sale;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\KitchenBoardService;
use App\Services\KitchenPrintService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Despacho: la pantalla de la caja cuando la cocina trabaja con papel
 * (comandera fisica). Ahi se ve lo que esta por salir y se mueve su estado:
 * «Salió a domicilio», «Entregado», «El cliente lo recogió», y se reimprime
 * la comanda.
 */
class DispatchController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'deliveries' => DeliveryResource::collection($this->deliveries->board($businessId)),
            'pickups' => DeliveryResource::collection($this->deliveries->pickups($businessId)),
            'couriers' => $this->couriers($businessId),
        ]);
    }

    /**
     * Empleados que hacen domicilios, para elegir quien lo lleva.
     *
     * @return list<array{id: int, name: string}>
     */
    private function couriers(int $businessId): array
    {
        // El scope de Spatie explota si el permiso todavia no existe.
        if (! Permission::query()->where('name', 'deliveries.handle')->exists()) {
            return [];
        }

        return User::permission('deliveries.handle')
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /** «Salió a domicilio»: imprime factura + ticket del domiciliario y lo pone en camino. */
    public function sendOut(Request $request, Sale $sale): DeliveryResource
    {
        $data = $request->validate(['courier_id' => ['nullable', 'integer']]);
        if (! $sale->is_delivery) {
            throw ValidationException::withMessages(['delivery' => 'Esta venta no es un domicilio.']);
        }

        $sale = $this->deliveries->dispatchFromCounter($request->user(), $sale, $data['courier_id'] ?? null);
        AuditLogger::log('delivery.dispatched', ['sale_id' => $sale->id, 'courier_id' => $sale->courier_id]);

        return new DeliveryResource($sale->fresh(['items.product', 'courier', 'onlineOrder']));
    }

    public function delivered(Request $request, Sale $sale): DeliveryResource
    {
        $sale = $this->deliveries->deliver($request->user(), $sale, fromCounter: true);
        AuditLogger::log('delivery.delivered', ['sale_id' => $sale->id, 'from_counter' => true]);

        return new DeliveryResource($sale->fresh(['items.product', 'courier', 'onlineOrder']));
    }

    /** Pedido online para recoger: el cliente se lo llevo. */
    public function pickedUp(Request $request, Sale $sale): JsonResponse
    {
        $order = app(KitchenBoardService::class)->dispatch($request->user(), $sale);

        return response()->json(['order_status' => $order->status]);
    }

    public function reprintKitchen(Request $request, Sale $sale): JsonResponse
    {
        $job = app(KitchenPrintService::class)->reprint($sale, $request->user());
        if ($job === null) {
            throw ValidationException::withMessages(['printer' => 'No hay una impresora de cocina conectada.']);
        }
        AuditLogger::log('printing.kitchen_reprinted', ['sale_id' => $sale->id]);

        return response()->json(new PrintJobResource($job->load('printer')), 201);
    }
}
