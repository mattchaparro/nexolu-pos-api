<?php

namespace App\Services\WhatsApp;

use App\Models\AiChannelIdentity;
use App\Models\AiChannelLinkChallenge;
use App\Models\User;
use App\Services\WhatsApp\Contracts\ChannelOtpSender;
use App\Services\WhatsApp\Exceptions\ChannelLinkException;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Orquesta la vinculacion de un numero de WhatsApp (u otro canal futuro) a
 * un usuario: pide un OTP, lo confirma, o desvincula.
 */
class ChannelLinkService
{
    public const OTP_TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private ChannelOtpSender $sender) {}

    /**
     * @throws ChannelLinkException si el numero no es valido o ya pertenece a otro usuario
     */
    public function start(User $user, string $channel, string $phone): AiChannelLinkChallenge
    {
        $externalId = ChannelPhone::normalize($phone);

        if ($externalId === null) {
            throw new ChannelLinkException('Ese numero no parece valido. Escribelo con indicativo, por ejemplo 3001234567.');
        }

        // Un numero pertenece a un solo usuario en toda la plataforma.
        $currentOwner = AiChannelIdentity::query()
            ->withoutGlobalScopes()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->whereNotNull('verified_at')
            ->first();

        if ($currentOwner !== null && $currentOwner->user_id !== $user->id) {
            throw new ChannelLinkException('Ese numero ya esta vinculado a otra cuenta.');
        }

        // Regeneracion: el reto anterior deja de servir.
        AiChannelLinkChallenge::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = AiChannelLinkChallenge::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'channel' => $channel,
            'external_id' => $externalId,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'attempts' => 0,
        ]);

        $this->sender->send($channel, $externalId, $code);

        return $challenge;
    }

    /**
     * @throws ChannelLinkException si no hay reto vigente, se agotaron intentos o el codigo no coincide
     */
    public function confirm(User $user, string $channel, string $code): AiChannelIdentity
    {
        $challenge = AiChannelLinkChallenge::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($challenge === null || $challenge->expires_at->isPast()) {
            throw new ChannelLinkException('El codigo expiro. Pide uno nuevo.');
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            throw new ChannelLinkException('Demasiados intentos. Pide un codigo nuevo.');
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            throw new ChannelLinkException('El codigo no coincide. Revisa e intenta de nuevo.');
        }

        return DB::transaction(function () use ($user, $channel, $challenge) {
            $challenge->forceFill(['consumed_at' => now()])->save();

            return AiChannelIdentity::withoutGlobalScopes()->updateOrCreate(
                ['channel' => $channel, 'external_id' => $challenge->external_id],
                ['business_id' => $user->business_id, 'user_id' => $user->id, 'verified_at' => now()]
            );
        });
    }

    public function unlink(User $user, string $channel): void
    {
        $identity = AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->first();

        if ($identity === null) {
            return;
        }

        $identity->delete();

        AiChannelLinkChallenge::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->delete();
    }

    public function identityFor(User $user, string $channel): ?AiChannelIdentity
    {
        return AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->whereNotNull('verified_at')
            ->first();
    }
}
