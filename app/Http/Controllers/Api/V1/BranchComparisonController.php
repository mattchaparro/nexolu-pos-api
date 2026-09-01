<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BranchComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comparativo entre sedes: cual vende mas, cual gasta mas, cuanto aporta cada
 * una al ingreso del negocio.
 *
 * A diferencia del resto de los reportes, este NO respeta la sede activa: un
 * comparativo que solo viera una sede no compararia nada. Por eso pide
 * business-admin - es informacion del negocio entero, no del local donde
 * trabaja quien consulta.
 */
class BranchComparisonController extends Controller
{
    public function __construct(private BranchComparisonService $comparison) {}

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->comparison->resolveRange(
            $request->query('from'),
            $request->query('to'),
        );

        return response()->json(
            $this->comparison->forPeriod((int) $request->user()->business_id, $from, $to)
        );
    }
}
