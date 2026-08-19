<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBillingProfileRequest;
use App\Http\Resources\Api\V1\BillingProfileResource;
use App\Models\BillingProfile;
use Illuminate\Http\Request;

/**
 * Datos de facturacion del negocio (ver App\Models\BillingProfile) - un
 * unico perfil, se completa una vez (registro o primer pago por PSE) y
 * queda prellenado de ahi en adelante con opcion de actualizarlo.
 */
class BillingProfileController extends Controller
{
    /**
     * Devuelve el perfil del negocio, o uno vacio si todavia no se ha
     * completado - el frontend siempre recibe la misma forma para
     * prellenar un formulario, sin tener que distinguir "no existe" de
     * "existe pero vacio".
     */
    public function show(Request $request): BillingProfileResource
    {
        $business = $request->user()->business;
        $profile = $business->billingProfile ?? new BillingProfile(['business_id' => $business->id]);

        return new BillingProfileResource($profile);
    }

    public function update(UpdateBillingProfileRequest $request): BillingProfileResource
    {
        $business = $request->user()->business;
        $profile = BillingProfile::updateOrCreate(
            ['business_id' => $business->id],
            $request->validated(),
        );

        return new BillingProfileResource($profile);
    }
}
