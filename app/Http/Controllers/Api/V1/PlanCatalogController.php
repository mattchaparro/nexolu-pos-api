<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\SubscriptionPricingService;
use App\Support\BusinessFeaturePresets;
use Illuminate\Http\JsonResponse;

/**
 * Version publica (sin auth) del catalogo de banderas/precios por plan - lo
 * consume el wizard de registro (elegir plan -> ver que trae -> apagar lo que
 * no quiere) antes de que exista una sesion. Mismos datos que
 * SuperAdmin\FeatureCatalogController::index(), pero ese vive detras del
 * middleware 'superadmin' a proposito (panel interno) - esta ruta es la
 * contraparte publica, no un acceso relajado de la interna.
 *
 * Tambien lo lee el home publico (nexolu-sites/sites/pos) al compilar: dias
 * de prueba, descuento anual y promo salen de aqui para que el home no
 * anuncie un precio distinto al que se cobra.
 */
class PlanCatalogController extends Controller
{
    public function index(SubscriptionPricingService $pricing): JsonResponse
    {
        return response()->json([
            'features' => BusinessFeaturePresets::catalog(),
            'plans' => [
                'basic' => ['price_cop' => BusinessFeaturePresets::planPriceCop('basic')],
                'full' => ['price_cop' => BusinessFeaturePresets::planPriceCop('full')],
            ],
            'trial_days' => Business::TRIAL_DAYS,
            'annual_discount_percent' => $pricing->annualDiscountPercent(),
            'promo' => $pricing->currentPromo(),
        ]);
    }
}
