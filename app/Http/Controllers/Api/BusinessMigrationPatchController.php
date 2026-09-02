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
 * abajo los otros, ni impedir ver el resultado de los que si corrieron.
 */
class BusinessMigrationPatchController extends Controller
{
    /**
     * Comando => argumentos, porque no todos reciben el negocio igual:
     * los tres parches de datos lo toman como --business y
     * branches:ensure-main como argumento posicional (acepta id o slug).
     *
     * branches:ensure-main va PRIMERO y no al final: todo lo operativo esta
     * scopeado por sede, asi que cualquier fila que quedara sin sede seria
     * invisible para su propio dueño.
     *
     * Desde 2026-09-01 BusinessDataExporter ya crea la sede principal y
     * estampa branch_id durante el export (no le queda opcion:
     * stock_movements y cash_closings exigen branch_id NOT NULL con FK, ver
     * su metodo createMainBranch). O sea que para una migracion nueva este
     * comando encuentra las filas ya asignadas y solo hace lo que el
     * exportador deliberadamente no hace: asignar los empleados migrados a
     * la sede (pivote branch_user) y sembrar branch_stocks. Sigue siendo
     * imprescindible - y como es idempotente, tambien cubre a los negocios
     * migrados con la version anterior del exportador, que si entraron sin
     * sede.
     *
     * @return array<string, array<string, mixed>>
     */
    private function commandsFor(Business $business): array
    {
        return [
            'branches:ensure-main' => ['business' => $business->id],
            'legacy:normalize-payment-methods' => ['--business' => $business->id],
            'payment-methods:migrate-catalog' => ['--business' => $business->id],
            'clients:backfill-links' => ['--business' => $business->id],
        ];
    }

    public function run(Business $business): JsonResponse
    {
        $results = [];

        foreach ($this->commandsFor($business) as $command => $arguments) {
            try {
                Artisan::call($command, $arguments);
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
