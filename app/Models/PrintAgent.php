<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un equipo del restaurante con el Nexolu Print Agent instalado.
 *
 * No es un usuario ni usa Sanctum a proposito: si el token del agente fuera
 * un token de Sanctum, abriria cualquier ruta `auth:sanctum` del POS (por
 * ejemplo GET /business). Su token solo lo entiende
 * App\Http\Middleware\AuthenticatePrintAgent, en las rutas /print-agent/*.
 */
#[Fillable([
    'business_id', 'branch_id', 'name', 'token_hash', 'pairing_code_hash', 'pairing_expires_at',
    'version', 'reported_printers', 'last_seen_at', 'revoked_at',
])]
#[Hidden(['token_hash', 'pairing_code_hash'])]
class PrintAgent extends Model
{
    use BelongsToBusiness, HasFactory;

    /** Sin reportarse en este tiempo, el POS lo muestra desconectado. */
    public const OFFLINE_AFTER_SECONDS = 60;

    protected function casts(): array
    {
        return [
            'reported_printers' => 'array',
            'pairing_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    public function isPaired(): bool
    {
        return $this->token_hash !== null && $this->revoked_at === null;
    }

    public function isOnline(): bool
    {
        return $this->isPaired()
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }
}
