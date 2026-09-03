<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\SystemLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel "Sistema": los logs de la aplicacion, sin entrar al servidor.
 *
 * Convive con Sentry a proposito y no lo reemplaza. Sentry agrupa y alerta,
 * pero solo ve lo que se le mando: si el DSN no esta configurado en un
 * ambiente, o el error ocurrio en un canal que no llega alli, el archivo
 * sigue siendo la unica fuente. Esta pantalla es para el momento en que hay
 * que mirar el log crudo y no se tiene (o no se quiere) una sesion SSH.
 */
class SystemController extends Controller
{
    private const PER_PAGE = 30;

    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'nullable', 'in:errors,logs'],
            'level' => ['sometimes', 'nullable', 'string', 'max:20'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $result = SystemLogReader::read($validated, $page, self::PER_PAGE);

        return response()->json([
            'data' => $result['entries'],
            'meta' => [
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $result['total'],
                'last_page' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
                'truncated' => $result['truncated'],
            ],
            'files' => array_map(fn (array $file) => [
                'name' => $file['name'],
                'size_bytes' => $file['size_bytes'],
                'modified_at' => $file['modified_at'],
            ], $result['files']),
            'levels' => [
                'errors' => SystemLogReader::ERROR_LEVELS,
                'logs' => SystemLogReader::INFO_LEVELS,
            ],
            'environment' => [
                'app_env' => (string) config('app.env'),
                'app_debug' => (bool) config('app.debug'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'timezone' => (string) config('app.timezone'),
                'log_channel' => (string) config('logging.default'),
                // Si no hay DSN, Sentry no esta recibiendo nada en este
                // ambiente y este visor es la unica fuente - conviene saberlo
                // antes de concluir que "no hay errores".
                'sentry_configured' => (bool) config('sentry.dsn'),
            ],
        ]);
    }
}
