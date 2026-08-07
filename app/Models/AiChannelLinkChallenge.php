<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reto de vinculacion de canal en curso (OTP de 6 digitos, TTL corto, un
 * solo uso). code_hash guarda solo el hash, nunca el codigo en claro.
 */
#[Fillable(['business_id', 'user_id', 'channel', 'external_id', 'code_hash', 'expires_at', 'attempts', 'consumed_at'])]
class AiChannelLinkChallenge extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
