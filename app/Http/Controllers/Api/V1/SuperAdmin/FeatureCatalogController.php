<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\BusinessFeaturePresets;
use Illuminate\Http\JsonResponse;

/**
 * Solo lectura del catalogo de banderas y su reparto entre plan Básico y
 * Full, para que un superadmin entienda de un vistazo que trae cada plan
 * sin tener que leer BusinessFeaturePresets. El mapeo plan->flags sigue
 * siendo codigo (no editable desde aca) - ver BusinessFeaturePresets::catalog().
 */
class FeatureCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'features' => BusinessFeaturePresets::catalog(),
            'plans' => [
                'basic' => ['price_cop' => BusinessFeaturePresets::planPriceCop('basic')],
                'full' => ['price_cop' => BusinessFeaturePresets::planPriceCop('full')],
            ],
        ]);
    }
}
