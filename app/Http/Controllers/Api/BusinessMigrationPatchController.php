<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Corre, scopeado a UN negocio, los parches de datos que hoy quedan
 * pendientes despues de que pos-saas (legacy) exporta ese negocio a esta
 * base (ver `businesses:migrate` del lado de pos-saas). Lo llama el
 * superadmin de pos-saas (boton "Correr parches" en Businesses/Show.vue),
 * server-to-server - no hay usuario humano de esta app en la llamada, se
 * autentica por API key de aplicacion (middleware legacy.admin-key), no
 * Sanctum.
 *
 * Cada comando corre en su propio try/catch: que uno falle no debe tirar
 * abajo los otros dos, ni impedir ver el resultado de los que si
 * corrieron.
 */
class BusinessMigrationPatchController extends Controller
{
    private const COMMANDS = [
        'legacy:normalize-payment-methods',
        'payment-methods:migrate-catalog',
        'clients:backfill-links',
    ];

    public function run(Business $business): JsonResponse
    {
        $results = [];

        foreach (self::COMMANDS as $command) {
            try {
                Artisan::call($command, ['--business' => $business->id]);
                $results[] = [
                    'command' => $command,
                    'ok' => true,
                    'output' => trim(Artisan::output()),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'command' => $command,
                    'ok' => false,
                    'output' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }
}
