<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Deja rastro de cada "no tienes permiso" (403) que responde el API: quién,
 * qué le faltó, qué endpoint pidió y desde qué pantalla del POS.
 *
 * Existe porque el frontend a veces consulta endpoints que el usuario no
 * puede ver (una pantalla que carga datos sin revisar el permiso), y eso le
 * muestra un aviso de "no tienes permiso" por algo que él no hizo. Sin este
 * rastro no hay forma de saber qué pantalla y qué permiso lo provocan.
 *
 * Se guarda en la auditoría (log_actions, acción `access.denied`) y se ve en
 * la Auditoría global de SuperAdmin. No aparece en la auditoría del negocio:
 * es diagnóstico de Nexolú, no una acción de su equipo.
 */
class AccessDenialLogger
{
    public const ACTION = 'access.denied';

    /** Cabecera con la que el POS dice desde qué pantalla salió la petición. */
    public const SCREEN_HEADER = 'X-Client-Route';

    /** Una pantalla que consulta cada 3 s no debe llenar la tabla. */
    private const REPEAT_WINDOW_MINUTES = 10;

    /**
     * @param  'permission'|'admin'|'feature'|'purchases'  $reason
     * @param  list<string>  $required  permisos o módulos que pedía la ruta
     */
    public static function record(Request $request, string $reason, array $required = []): void
    {
        // Un fallo al dejar el rastro no puede cambiar la respuesta: el 403
        // sale igual.
        try {
            $user = $request->user();
            if (! $user) {
                return;
            }

            $route = $request->route();
            $endpoint = $route?->getName() ?: ($route?->uri() ?? $request->path());
            $screen = self::screen($request);

            $key = 'access-denied:'.$user->id.':'.md5(implode('|', [$reason, $request->method(), $endpoint, (string) $screen]));
            if (! Cache::add($key, 1, now()->addMinutes(self::REPEAT_WINDOW_MINUTES))) {
                return;
            }

            AuditLogger::log(self::ACTION, [
                'reason' => $reason,
                'required' => array_values($required),
                'endpoint' => $endpoint,
                'screen' => $screen,
                'roles' => $user->getRoleNames()->values()->all(),
            ]);
        } catch (\Throwable) {
            // Sin rastro, pero con la respuesta correcta.
        }
    }

    /**
     * La pantalla la manda el cliente, así que se acota: solo el nombre de una
     * ruta del POS ("sales.create"), nunca texto libre que termine en la base.
     */
    private static function screen(Request $request): ?string
    {
        $value = trim((string) $request->header(self::SCREEN_HEADER));

        return preg_match('/^[A-Za-z0-9._\-]{1,80}$/', $value) ? $value : null;
    }
}
