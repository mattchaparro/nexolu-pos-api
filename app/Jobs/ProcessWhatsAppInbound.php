<?php

namespace App\Jobs;

use App\Services\AiChatService;
use App\Services\WhatsApp\IdentityResolver;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Support\WhatsAppTextFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Procesa un mensaje de texto entrante de WhatsApp: resuelve identidad,
 * proxea al IA Core (mismo AiChatService que usa el chat web, canal
 * 'whatsapp') y responde por WhatsApp Cloud API.
 *
 * Fase 1 de esta integracion: sin WhatsApp Flows todavia (confirmacion de
 * borradores por formulario nativo). Si el IA Core devuelve borradores
 * pendientes, se avisa que se confirman desde el POS - eso llega en una
 * fase siguiente, junto con el proxy de /v1/drafts/{id}/confirm.
 */
class ProcessWhatsAppInbound implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const CHANNEL = 'whatsapp';

    private const AGENT = 'cajero';

    private const CONVERSATION_CACHE_TTL_HOURS = 24;

    public function __construct(
        public readonly string $from,
        public readonly string $text,
        public readonly ?string $wamid = null,
    ) {}

    public function handle(IdentityResolver $resolver, AiChatService $chat, WhatsAppCloudClient $client): void
    {
        $user = $resolver->resolveUser(self::CHANNEL, $this->from);

        if ($user === null) {
            $client->sendText($this->from, $this->onboardingMessage());

            return;
        }

        if ($this->wamid !== null) {
            $client->markAsReadWithTyping($this->from, $this->wamid);
        }

        $conversationCacheKey = "wa_conversation:{$user->id}";
        $conversationId = Cache::get($conversationCacheKey);

        // Un job de cola no tiene sesion HTTP: se autentica solo para la
        // duracion del job (los mismos servicios que arman el contexto leen
        // auth()->user()).
        Auth::setUser($user);

        try {
            $context = [
                'business_id' => (string) $user->business_id,
                'user_id' => (string) $user->id,
                'is_admin' => $user->hasRole('admin'),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                'features' => $user->business->enabledFeatureNames(),
                'channel' => self::CHANNEL,
                'timezone' => 'America/Bogota',
                'locale' => 'es',
            ];

            $result = $chat->send(self::AGENT, $this->text, $context, $conversationId);

            if (! empty($result['conversation_id'])) {
                Cache::put($conversationCacheKey, $result['conversation_id'], now()->addHours(self::CONVERSATION_CACHE_TTL_HOURS));
            }

            $message = WhatsAppTextFormatter::fromMarkdown((string) ($result['text'] ?? ''));

            if (! empty($result['drafts'])) {
                $message .= "\n\n📝 Tienes un borrador pendiente de confirmar. Por ahora confirmalo desde el POS.";
            }

            $client->sendText($this->from, $message);
        } catch (RuntimeException $e) {
            $client->sendText($this->from, $e->getMessage());
        } catch (\Throwable $e) {
            $client->sendText($this->from, 'Uf, algo fallo de mi lado. Intenta de nuevo en un momento.');

            try {
                Log::error('whatsapp.inbound: fallo al procesar', ['from' => $this->from, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        } finally {
            Auth::forgetGuards();
        }
    }

    private function onboardingMessage(): string
    {
        return 'Hola 👋 Para usar el asistente de Nexolú por WhatsApp, primero vincula '
            .'este numero desde el POS: entra a tu cuenta, ve al Asistente y elige '
            .'"Asistente por WhatsApp".';
    }
}
