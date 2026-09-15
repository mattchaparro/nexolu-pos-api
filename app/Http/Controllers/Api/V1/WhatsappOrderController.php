<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WhatsappOrderResource;
use App\Models\WhatsappOrder;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\WhatsApp\WhatsAppOrderConverter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * La bandeja de pedidos de WhatsApp: lo que el camino automatico dejo en
 * pending_review (stock, producto retirado, precio raro) lo resuelve un
 * humano aca - aceptar reintenta la MISMA conversion del job
 * (WhatsAppOrderConverter, precio del servidor y stock con lock) ya con el
 * empleado como responsable de la venta; rechazar avisa al cliente.
 */
class WhatsappOrderController extends Controller
{
    public function __construct(
        private WhatsAppOrderConverter $converter,
        private MessagingChannel $whatsapp,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = WhatsappOrder::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        return WhatsappOrderResource::collection($query->paginate(25)->withQueryString());
    }

    public function accept(Request $request, WhatsappOrder $whatsappOrder): WhatsappOrderResource
    {
        if ($whatsappOrder->status === WhatsappOrder::STATUS_CREATED) {
            throw ValidationException::withMessages([
                'status' => ['Este pedido ya tiene una venta creada.'],
            ]);
        }

        $sale = $this->converter->convert($whatsappOrder, $request->user());

        $this->whatsapp->sendText(
            $whatsappOrder->phone,
            '✅ ¡Tu pedido quedó confirmado! Total: $'.number_format((float) $sale->total, 0, ',', '.').'.',
            $whatsappOrder->business_id,
            'pedido_whatsapp',
        );

        return new WhatsappOrderResource($whatsappOrder->refresh());
    }

    public function reject(Request $request, WhatsappOrder $whatsappOrder): WhatsappOrderResource
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $whatsappOrder->update([
            'status' => WhatsappOrder::STATUS_REJECTED,
            'error' => $data['reason'] ?? $whatsappOrder->error,
        ]);

        $this->whatsapp->sendText(
            $whatsappOrder->phone,
            '😔 No pudimos tomar tu pedido esta vez'
                .(isset($data['reason']) && $data['reason'] ? ': '.$data['reason'] : '.')
                .' Escríbenos y lo resolvemos.',
            $whatsappOrder->business_id,
            'pedido_whatsapp',
        );

        return new WhatsappOrderResource($whatsappOrder);
    }
}
