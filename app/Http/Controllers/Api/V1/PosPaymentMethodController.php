<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestPaymentMethodSupportRequest;
use App\Http\Requests\Api\V1\UpdateBusinessPaymentMethodsRequest;
use App\Mail\PaymentMethodSupportRequestMail;
use App\Models\Business;
use App\Models\PosPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Selector de Ajustes > Medios de pago del negocio: elige del catalogo
 * global (PosPaymentMethod, gestionado por SuperAdmin) en vez de escribir
 * texto libre. Guardar aca es lo que migra al negocio del JSON legacy
 * (Business::payment_methods) al catalogo normalizado - ver la nota en
 * Business::paymentMethods(). Nunca se borra una fila de pivote, solo se
 * activa/desactiva (is_enabled), para no romper transacciones historicas
 * que ya usaron ese medio de pago.
 */
class PosPaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->buildResponse($this->currentBusiness($request)));
    }

    public function update(UpdateBusinessPaymentMethodsRequest $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $sync = collect($request->validated('methods'))
            ->mapWithKeys(fn (array $method) => [
                $method['pos_payment_method_id'] => ['is_enabled' => $method['is_enabled']],
            ])
            ->all();

        $business->posPaymentMethods()->syncWithoutDetaching($sync);

        return response()->json($this->buildResponse($business->fresh()));
    }

    /**
     * "¿No está el que necesitas? Contacta a soporte" - el negocio no puede
     * crear medios de pago propios (evita que se repita el texto libre sin
     * normalizar que este refactor reemplaza), asi que esto es lo unico que
     * puede hacer: pedirle al equipo de Nexolu que lo agregue al catalogo.
     */
    public function requestSupport(RequestPaymentMethodSupportRequest $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        Mail::to(config('mail.support_address'))->send(new PaymentMethodSupportRequestMail(
            $business,
            $request->user(),
            $request->validated('message'),
        ));

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponse(Business $business): array
    {
        $business->loadMissing('posPaymentMethods');

        $selectedIds = $business->posPaymentMethods->pluck('id');
        $enabledById = $business->posPaymentMethods->pluck('pivot.is_enabled', 'id');

        $catalog = PosPaymentMethod::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $selectedIds))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        $migrated = $business->posPaymentMethods->isNotEmpty();

        return [
            'data' => $catalog->map(fn (PosPaymentMethod $method) => [
                'id' => $method->id,
                'key' => $method->key,
                'label' => $method->label,
                'is_active' => $method->is_active,
                'is_enabled' => (bool) ($enabledById[$method->id] ?? false),
            ])->values(),
            'migrated' => $migrated,
            'legacy_payment_methods' => $migrated ? [] : $business->paymentMethods(),
        ];
    }

    private function currentBusiness(Request $request): Business
    {
        $business = $request->user()?->business;

        if (! $business) {
            throw new NotFoundHttpException('El usuario autenticado no tiene un negocio asociado.');
        }

        return $business;
    }
}
