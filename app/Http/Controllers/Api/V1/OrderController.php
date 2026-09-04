<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Bandeja de pedidos online del comerciante.
 *
 * Confirmar un pedido es lo unico que crea una venta: ver
 * OrderService::transition(). El resto de estados son de fulfillment y no
 * tocan ni caja ni inventario.
 */
class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::with('items')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // Buscar por lo que el comerciante tiene a mano cuando alguien le
        // reclama: el numero que le dijeron, o el nombre/telefono del que
        // llamo. Filtrar por estado no alcanza cuando hay cuarenta al dia.
        if ($request->filled('search')) {
            $termino = trim((string) $request->string('search'));

            $query->where(function ($q) use ($termino) {
                $q->where('customer_name', 'like', "%{$termino}%")
                    ->orWhere('customer_phone', 'like', "%{$termino}%");

                // El numero se busca exacto: "12" no debe traer el 120 y el
                // 512. Quien busca un numero de pedido lo sabe completo.
                if (ctype_digit($termino)) {
                    $q->orWhere('number', (int) $termino);
                }
            });
        }

        return OrderResource::collection($query->paginate(20)->withQueryString());
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(['items', 'history.user']));
    }

    public function updateStatus(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::TRANSITIONS))],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Solo pesa al confirmar: es el medio con el que se registra la
            // venta. Se valida contra el catalogo del negocio en
            // OrderService::createSaleFor, no aca, para que la regla viva en
            // un solo sitio.
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $updated = $this->orders->transition(
            $request->user(),
            $order,
            $data['status'],
            $data['note'] ?? null,
            $data['payment_method'] ?? null,
        );

        $this->orders->notifyBuyer($updated, $data['status']);

        AuditLogger::log('online_order.status_changed', [
            'order_id' => $order->id,
            'number' => $order->number,
            'status' => $data['status'],
            'sale_id' => $updated->sale_id,
        ]);

        return new OrderResource($updated->load(['items', 'history.user']));
    }

    /** Cuantos pedidos esperan atencion, para el badge del menu. */
    public function pendingCount(): array
    {
        return ['pending' => Order::where('status', Order::STATUS_PENDING)->count()];
    }
}
