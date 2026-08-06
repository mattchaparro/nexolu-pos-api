<?php

namespace App\Http\Controllers\Api;

use App\Capabilities\Registry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Catalogo de permisos/features reales por herramienta, consultado por el
 * Nexolu IA Core (repo Python aparte) y cacheado ahi ~24h (ver
 * RemoteToolCatalog en ese repo). Existe para que un cambio de permiso o
 * feature de una capacidad (ver App\Capabilities\*) se refleje del lado del
 * IA Core sin tocar ni redeployar ese repo - Laravel sigue siendo la unica
 * fuente de verdad de que permiso protege que herramienta, en vez de tener
 * ese dato duplicado y potencialmente desactualizado en otro repo.
 */
class AiToolCatalogController extends Controller
{
    public function __construct(private Registry $registry) {}

    public function index(): JsonResponse
    {
        $tools = [];

        foreach ($this->registry->names() as $name) {
            $capability = $this->registry->resolve($name);

            $tools[$name] = [
                'required_permission' => $capability->requiredPermission(),
                'required_feature' => $capability->requiredFeature(),
            ];
        }

        return response()->json(['tools' => $tools]);
    }
}
