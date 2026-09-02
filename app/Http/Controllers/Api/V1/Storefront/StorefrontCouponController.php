<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validar un cupon ANTES de comprar.
 *
 * Existe para que el comprador vea el descuento aplicado mientras decide, no
 * despues de dar clic en comprar. El calculo real vuelve a hacerse al crear
 * el pedido (`OrderService::resolveCoupon`) contra la base: esto es
 * informacion para la pantalla, nunca la fuente del precio.
 *
 * Nunca dice si un codigo EXISTE cuando no aplica: un codigo malo y uno
 * bueno de otro negocio responden lo mismo. Con un endpoint publico y sin
 * autenticacion, distinguirlos convertiria esto en un adivinador de cupones
 * ajenos.
 */
class StorefrontCouponController extends Controller
{
    public function validateCode(Request $request): JsonResponse
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            // El subtotal que ve el comprador, solo para poder responder
            // "aplica desde $X" con precision. No fija ningun precio: el del
            // pedido se recalcula contra la base al comprar.
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $subtotal = (float) $data['subtotal'];
        $coupon = Discount::findCoupon($business->id, $data['code']);

        if ($coupon === null || ! $coupon->isCoupon()) {
            return response()->json([
                'valid' => false,
                'message' => 'Ese cupón no existe.',
            ]);
        }

        $motivo = $coupon->rejectionReason($subtotal);
        if ($motivo !== null) {
            return response()->json(['valid' => false, 'message' => $motivo]);
        }

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'label' => $coupon->name,
            'amount' => $coupon->computeAmount($subtotal),
        ]);
    }
}
