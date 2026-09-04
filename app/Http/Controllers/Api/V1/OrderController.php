<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\OrderNote;
use App\Services\OrderNoteService;
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
    public function __construct(private OrderService $orders, private OrderNoteService $notes) {}

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
        return new OrderResource($order->load(['items', 'history.user', 'notes.user']));
    }

    /**
     * Anota algo sobre el pedido, y si es para el comprador, se lo manda.
     *
     * Los canales se validan contra los que el pedido tiene DE VERDAD (ver
     * OrderNoteService::availableChannels): quien compro como invitado pudo
     * dejar solo el telefono, y aceptar "correo" para fallar despues seria
     * decirle al comerciante que escribio cuando no.
     */
    public function storeNote(Request $request, Order $order): OrderResource
    {
        $disponibles = $this->notes->availableChannels($order);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'visibility' => ['required', Rule::in([OrderNote::VISIBILITY_INTERNAL, OrderNote::VISIBILITY_CUSTOMER])],
            'channels' => [
                'array',
                Rule::requiredIf(fn () => $request->input('visibility') === OrderNote::VISIBILITY_CUSTOMER),
            ],
            'channels.*' => [Rule::in($disponibles)],
        ], [
            'channels.*.in' => 'Este comprador no dejó ese medio de contacto.',
        ]);

        $note = $this->notes->add(
            $request->user(),
            $order,
            trim($data['body']),
            $data['visibility'],
            $data['channels'] ?? [],
        );

        AuditLogger::log('online_order.note_added', [
            'order_id' => $order->id,
            'number' => $order->number,
            'visibility' => $note->visibility,
            'channels' => $note->channels ?? [],
        ]);

        return new OrderResource($order->fresh()->load(['items', 'history.user', 'notes.user']));
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

        // Las notas van tambien aca: el front guarda esta respuesta como el
        // detalle del pedido, y sin ellas cambiar de estado vaciaria el hilo
        // de notas en pantalla.
        return new OrderResource($updated->load(['items', 'history.user', 'notes.user']));
    }

    /** Cuantos pedidos esperan atencion, para el badge del menu. */
    public function pendingCount(): array
    {
        return ['pending' => Order::where('status', Order::STATUS_PENDING)->count()];
    }
}
