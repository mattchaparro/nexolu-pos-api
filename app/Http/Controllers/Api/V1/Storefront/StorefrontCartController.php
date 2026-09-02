<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\StoreCart;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espejo del carrito del comprador en el servidor.
 *
 * La tienda sigue mandando el carrito desde `localStorage` al comprar: esto
 * NO es la fuente de la compra, es una copia para poder recuperar el carrito
 * de quien se fue sin comprar. El precio y la disponibilidad se releen contra
 * la base al hacer el pedido, igual que siempre.
 *
 * El `token` lo genera la tienda y es lo unico que identifica a un comprador
 * sin cuenta. Por eso no hay endpoint para LEER un carrito por token: leerlo
 * solo serviria para el enlace de recuperacion, que ya viaja firmado, y
 * ofrecerlo abierto convertiria un token adivinado en el carrito de otro.
 */
class StorefrontCartController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'items' => ['present', 'array', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:200'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            // Opcionales SIEMPRE: pedir el correo antes de comprar cuesta
            // conversion. Se ofrece, no se exige.
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
        ]);

        // Un carrito vacio no es un carrito abandonado: es alguien que
        // saco lo que habia puesto. Se borra en vez de quedar como una fila
        // que el job tendria que aprender a ignorar.
        if ($data['items'] === []) {
            StoreCart::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('token', $data['token'])
                ->delete();

            return response()->json(['ok' => true]);
        }

        $subtotal = collect($data['items'])->sum(
            fn (array $item) => (float) ($item['unit_price'] ?? 0) * (int) $item['quantity']
        );

        $cart = StoreCart::withoutGlobalScopes()->firstOrNew([
            'business_id' => $business->id,
            'token' => $data['token'],
        ]);

        // Un carrito ya convertido no se vuelve a tocar: el comprador
        // siguio navegando despues de comprar y esto lo devolveria a la cola
        // de "abandonados".
        if ($cart->exists && $cart->isConverted()) {
            return response()->json(['ok' => true]);
        }

        $cart->fill([
            'items' => $data['items'],
            'subtotal' => round($subtotal, 2),
            'last_activity_at' => now(),
        ]);

        // El contacto solo se pisa con algo real: un formulario vaciado no
        // debe borrar el correo que ya habia dado.
        foreach (['contact_name', 'contact_phone', 'contact_email'] as $campo) {
            $valor = trim((string) ($data[$campo] ?? ''));
            if ($valor !== '') {
                $cart->{$campo} = $valor;
            }
        }

        $cart->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Devuelve el carrito de un enlace de recuperacion.
     *
     * La unica autenticacion es la FIRMA de la URL (middleware `signed`),
     * igual que el snooze de inventario bajo y los comprobantes publicos.
     * Por eso no existe un GET libre por token: sin firma, adivinar un token
     * seria ver el carrito de otro.
     *
     * Los precios que devuelve son los que el comprador vio y sirven para
     * pintar. El pedido los relee contra la base igual (`resolveLines`), asi
     * que un carrito viejo no compra a precio viejo.
     */
    public function recover(Request $request): JsonResponse
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $cart = StoreCart::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('token', (string) $request->query('token'))
            ->first();

        abort_if($cart === null, 404);

        return response()->json([
            'token' => $cart->token,
            'items' => $cart->items ?? [],
            'contact_name' => $cart->contact_name,
            'contact_phone' => $cart->contact_phone,
            'contact_email' => $cart->contact_email,
        ]);
    }
}
