<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\StorePosPaymentMethodRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdatePosPaymentMethodRequest;
use App\Http\Resources\Api\V1\SuperAdmin\PosPaymentMethodResource;
use App\Models\PosPaymentMethod;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catalogo global de medios de pago del POS - ver PosPaymentMethod y la
 * nota en schema.sql sobre por que no se llama "payment_methods" a secas.
 * Sin destroy() a proposito: un medio de pago del catalogo nunca se borra,
 * solo se desactiva (is_active), porque su `key` puede estar grabado en
 * transacciones de cualquier negocio que lo tenga asignado.
 */
class PosPaymentMethodController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PosPaymentMethodResource::collection(
            PosPaymentMethod::withCount('businesses')->orderBy('sort_order')->orderBy('label')->get()
        );
    }

    public function store(StorePosPaymentMethodRequest $request): PosPaymentMethodResource
    {
        return new PosPaymentMethodResource(PosPaymentMethod::create($request->validated()));
    }

    public function update(UpdatePosPaymentMethodRequest $request, PosPaymentMethod $posPaymentMethod): PosPaymentMethodResource
    {
        $posPaymentMethod->update($request->validated());

        return new PosPaymentMethodResource($posPaymentMethod->fresh());
    }
}
