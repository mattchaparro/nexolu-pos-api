<?php

namespace App\Services;

use App\Models\Business;
use App\Models\PrintAgent;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vincular, mantener vivo y revocar el Nexolu Print Agent de un local.
 *
 * Vincular es en dos pasos para que nadie copie un token largo a mano: el
 * admin crea el equipo en el POS y recibe un codigo de 6 digitos; el
 * instalador del agente pide ese codigo y lo cambia por su token. El codigo
 * vence en minutos y se usa una sola vez.
 */
class PrintAgentService
{
    public const PAIRING_MINUTES = 10;

    public const TOKEN_PREFIX = 'pa_';

    /**
     * Crea el equipo y su codigo de vinculacion.
     *
     * @return array{agent: PrintAgent, code: string}
     */
    public function create(Business $business, string $name, ?int $branchId): array
    {
        $agent = PrintAgent::create([
            'business_id' => $business->id,
            'branch_id' => $branchId,
            'name' => $name,
        ]);

        return ['agent' => $agent, 'code' => $this->issuePairingCode($agent)];
    }

    /**
     * Codigo nuevo para (re)vincular un equipo: el anterior deja de servir.
     * Re-vincular invalida el token viejo, por si el equipo cambio de manos.
     */
    public function issuePairingCode(PrintAgent $agent): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $taken = PrintAgent::withoutGlobalScope('business')
                ->where('pairing_code_hash', $this->hash($code))
                ->where('pairing_expires_at', '>', now())
                ->exists();
        } while ($taken);

        $agent->forceFill([
            'pairing_code_hash' => $this->hash($code),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_MINUTES),
            'token_hash' => null,
            'revoked_at' => null,
        ])->save();

        return $code;
    }

    /**
     * El agente cambia el codigo por su token. El token se devuelve una
     * sola vez: aqui solo queda su hash.
     *
     * @return array{agent: PrintAgent, token: string}
     */
    public function pair(string $code, ?string $version): array
    {
        $agent = PrintAgent::withoutGlobalScope('business')
            ->where('pairing_code_hash', $this->hash(trim($code)))
            ->where('pairing_expires_at', '>', now())
            ->first();

        if ($agent === null) {
            throw ValidationException::withMessages([
                'code' => 'El código no es válido o ya venció. Genera uno nuevo en Nexolú › Configuración › Impresoras.',
            ]);
        }

        $token = self::TOKEN_PREFIX.Str::random(48);
        $agent->forceFill([
            'token_hash' => $this->hash($token),
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
            'revoked_at' => null,
            'version' => $version,
            'last_seen_at' => now(),
        ])->save();

        return ['agent' => $agent, 'token' => $token];
    }

    /** Agente activo dueño de un token, o null. */
    public function findByToken(string $token): ?PrintAgent
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        return PrintAgent::withoutGlobalScope('business')
            ->where('token_hash', $this->hash($token))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Señal de vida: la version del agente y las impresoras que ve en
     * Windows, para elegirlas desde el POS sin escribir nombres a mano.
     *
     * @param  list<string>|null  $reportedPrinters
     */
    public function heartbeat(PrintAgent $agent, ?string $version, ?array $reportedPrinters): PrintAgent
    {
        $attributes = ['last_seen_at' => now(), 'version' => $version ?? $agent->version];
        if ($reportedPrinters !== null) {
            $attributes['reported_printers'] = array_values(array_unique(array_map('strval', $reportedPrinters)));
        }
        $agent->forceFill($attributes)->save();

        return $agent;
    }

    /** Toca el reloj sin escribir en cada consulta de la cola (una vez cada 10 s basta para "en linea"). */
    public function touch(PrintAgent $agent): void
    {
        if ($agent->last_seen_at === null || $agent->last_seen_at->lt(now()->subSeconds(10))) {
            $agent->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function revoke(PrintAgent $agent): void
    {
        $agent->forceFill([
            'revoked_at' => now(),
            'token_hash' => null,
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
        ])->save();
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
