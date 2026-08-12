<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServiceWorkflowResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lectura del workflow de etapas asignado a ESTE negocio (uno como mucho,
 * ver Business::serviceWorkflow()). Distinto del CRUD de plantillas en
 * Api\V1\SuperAdmin\ServiceWorkflowController: un negocio solo consume el
 * suyo, no lo crea ni lo edita.
 */
class BusinessServiceWorkflowController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $workflow = $business->serviceWorkflow()->with('stages')->first();

        // ->setData(), no response()->json($x) directo: el constructor de
        // Symfony\JsonResponse hace `$data ??= new ArrayObject()`, asi que
        // pasarle null ahi devuelve "{}" en vez de "null" en el body -
        // setData() se llama despues del constructor y no tiene ese
        // coalesce, es la unica forma de emitir un JSON null real de tope.
        // Tampoco new ServiceWorkflowResource(null): un JsonResource
        // envolviendo un modelo null serializa como objeto con todos los
        // campos en null, no como JSON null.
        return response()->json()->setData($workflow ? new ServiceWorkflowResource($workflow) : null);
    }
}
