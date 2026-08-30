<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BusinessStoreInteraction;
use App\Support\ChannelPhone;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El boton de WhatsApp de la tienda pasa por aca antes de ir a wa.co.
 *
 * El rodeo tiene un motivo: si el enlace apunta directo a wa.me, no hay
 * forma de saber cuanta gente escribe desde la tienda. Un comerciante que
 * no ve ese numero no sabe si su tienda sirve.
 *
 * El destino se arma SIEMPRE en el servidor con el numero guardado del
 * negocio. Aceptarlo por parametro convertiria esto en un redirector
 * abierto: cualquiera podria mandar a la gente a donde quisiera con una URL
 * que empieza por tienda.nexolu.co.
 */
class StorefrontContactController extends Controller
{
    /**
     * Mensajes segun de donde salio el clic. Tambien se arman aca: el
     * cliente solo dice el contexto, no el texto.
     */
    private const MESSAGES = [
        'home' => 'Hola, te escribo desde tu tienda online.',
        'product' => 'Hola, me interesa este producto: ',
        'order' => 'Hola, te escribo por mi pedido ',
    ];

    public function whatsapp(Request $request): RedirectResponse
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $settings = $business->storeSettings()->withoutGlobalScopes()->first();
        $phone = ChannelPhone::normalize((string) ($settings?->whatsapp_number ?? ''));
        abort_if($phone === null, 404);

        $data = $request->validate([
            // Corto y acotado: es lo unico que viene de afuera y termina en
            // la base.
            'context' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z]+(:[A-Za-z0-9 .\-]{0,30})?$/'],
        ]);
        $context = $data['context'] ?? 'home';

        BusinessStoreInteraction::create([
            'business_id' => $business->id,
            'type' => BusinessStoreInteraction::TYPE_WHATSAPP,
            'context' => $context,
        ]);

        return redirect()->away($this->target($phone, $context));
    }

    private function target(string $phone, string $context): string
    {
        [$kind, $detail] = array_pad(explode(':', $context, 2), 2, '');
        $message = (self::MESSAGES[$kind] ?? self::MESSAGES['home']).$detail;

        return 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode(trim($message));
    }
}
