<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Lee los archivos de log de la aplicacion para el panel "Sistema".
 *
 * A diferencia del ErrorLogsController del legacy, que cargaba en memoria
 * TODOS los archivos de storage/logs enteros en cada request, aca se lee solo
 * la COLA de cada archivo y se corta apenas hay suficientes entradas. El log
 * de un servidor con trafico crece a cientos de MB: cargarlo completo no es
 * lento, es un OOM que tumba el panel justo cuando se lo necesita para
 * entender por que algo se cayo.
 *
 * Leer desde el final parte la primera entrada por la mitad; el parser
 * descarta las lineas sueltas hasta el primer encabezado con fecha, asi que
 * el unico costo es perder esa entrada, que igual es la mas vieja del tramo.
 */
final class SystemLogReader
{
    /** Cuanto se lee de la cola de cada archivo. */
    private const MAX_BYTES_PER_FILE = 2 * 1024 * 1024;

    /** Tope de entradas que se sostienen en memoria antes de filtrar. */
    private const MAX_SCANNED_ENTRIES = 3000;

    /** Niveles que cuentan como "algo se rompio". */
    public const ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** Ruido operativo normal: util para seguir un flujo, no para alarmarse. */
    public const INFO_LEVELS = ['INFO', 'DEBUG', 'NOTICE', 'WARNING'];

    /**
     * @param  array{tab?: ?string, level?: ?string, search?: ?string, date?: ?string}  $filters
     * @return array{entries: list<array<string, mixed>>, total: int, files: list<array<string, mixed>>, truncated: bool}
     */
    public static function read(array $filters, int $page, int $perPage): array
    {
        $files = self::logFiles();
        $entries = [];
        $truncated = false;

        foreach ($files as $file) {
            if (count($entries) >= self::MAX_SCANNED_ENTRIES) {
                $truncated = true;
                break;
            }

            // Cada archivo viene en orden cronologico ascendente; se invierte
            // para que lo mas reciente quede primero, que es como se lee un log.
            $parsed = array_reverse(self::parse(self::tail($file['path'])));
            $entries = array_merge($entries, $parsed);
        }

        $filtered = array_values(array_filter($entries, fn (array $entry) => self::matches($entry, $filters)));

        return [
            'entries' => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'total' => count($filtered),
            'files' => $files,
            // Se avisa cuando se corto: sin esto, "no aparece el error que
            // estoy buscando" y "ese error no existe" se ven igual.
            'truncated' => $truncated,
        ];
    }

    /** @return list<array{name: string, path: string, size_bytes: int, modified_at: string}> */
    public static function logFiles(): array
    {
        $paths = glob(storage_path('logs').'/*.log') ?: [];

        $files = array_map(fn (string $path) => [
            'name' => basename($path),
            'path' => $path,
            'size_bytes' => (int) filesize($path),
            'modified_at' => Carbon::createFromTimestamp(filemtime($path))->toIso8601String(),
        ], $paths);

        usort($files, fn (array $a, array $b) => strcmp($b['modified_at'], $a['modified_at']));

        return $files;
    }

    /** Ultimos MAX_BYTES_PER_FILE bytes del archivo. */
    private static function tail(string $path): string
    {
        $size = (int) filesize($path);
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return '';
        }

        if ($size > self::MAX_BYTES_PER_FILE) {
            fseek($handle, -self::MAX_BYTES_PER_FILE, SEEK_END);
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parse(string $content): array
    {
        $entries = [];
        $current = null;

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] ([\w-]+)\.(\w+): (.*)$/', $line, $match)) {
                if ($current !== null) {
                    $entries[] = self::finalize($current);
                }

                $current = [
                    'timestamp' => str_replace('T', ' ', $match[1]),
                    'channel' => $match[2],
                    'level' => strtoupper($match[3]),
                    'message' => $match[4],
                    'trace' => [],
                ];

                continue;
            }

            // Linea suelta: o es parte del stacktrace de la entrada actual, o
            // es la cola cortada de una entrada anterior que ya no existe.
            if ($current !== null && trim($line) !== '') {
                $current['trace'][] = rtrim($line);
            }
        }

        if ($current !== null) {
            $entries[] = self::finalize($current);
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function finalize(array $entry): array
    {
        $message = (string) $entry['message'];

        // El contexto va como JSON al final de la linea. Se separa para poder
        // mostrarlo aparte: pegado al mensaje, un contexto largo lo tapa.
        $context = null;
        if (preg_match('/\s(\{.*\})\s*$/', $message, $match)) {
            $decoded = json_decode($match[1], true);
            if (is_array($decoded)) {
                $context = $decoded;
                $message = trim(substr($message, 0, -strlen($match[0])));
            }
        }

        // Excepcion, cuando la hay: 'App\Exceptions\Foo: mensaje in /ruta:123'.
        // Se exige que el nombre lleve namespace (al menos una barra) en vez
        // de que termine en Exception/Error: la mitad de las excepciones del
        // proyecto no terminan asi (PaymentFailed, QuotaExceeded), y exigirlo
        // por prefijo confundiria un mensaje comun con dos puntos
        // ('ai_platform_usage: ...') con una clase.
        $exceptionClass = null;
        if (preg_match('/^([A-Za-z_][\w]*(?:\\\\[A-Za-z_][\w]*)+):\s/', $message, $match)) {
            $exceptionClass = $match[1];
        }

        return [
            'timestamp' => $entry['timestamp'],
            'channel' => $entry['channel'],
            'level' => $entry['level'],
            'message' => $message,
            'exception_class' => $exceptionClass,
            'context' => $context,
            // Solo las primeras lineas del stacktrace: el resto es vendor.
            'trace' => array_slice($entry['trace'], 0, 15),
            'trace_lines' => count($entry['trace']),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{tab?: ?string, level?: ?string, search?: ?string, date?: ?string}  $filters
     */
    private static function matches(array $entry, array $filters): bool
    {
        $levels = ($filters['tab'] ?? 'errors') === 'logs' ? self::INFO_LEVELS : self::ERROR_LEVELS;

        if (! in_array($entry['level'], $levels, true)) {
            return false;
        }

        if (! empty($filters['level']) && $entry['level'] !== strtoupper((string) $filters['level'])) {
            return false;
        }

        if (! empty($filters['date']) && ! str_starts_with((string) $entry['timestamp'], (string) $filters['date'])) {
            return false;
        }

        if (! empty($filters['search'])) {
            $needle = mb_strtolower((string) $filters['search']);
            $haystack = mb_strtolower($entry['message'].' '.($entry['exception_class'] ?? '').' '.implode(' ', $entry['trace']));

            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
}
