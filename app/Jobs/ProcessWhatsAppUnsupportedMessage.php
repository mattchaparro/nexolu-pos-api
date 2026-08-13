<?php

namespace App\Jobs;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\WhatsApp\IdentityResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa que un tipo de mensaje (audio, imagen, etc.) todavia no se puede
 * leer, en vez de quedar en silencio.
 */
class ProcessWhatsAppUnsupportedMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const LABELS = [
        'audio' => 'audios',
        'image' => 'imagenes',
        'video' => 'videos',
        'document' => 'documentos',
        'sticker' => 'stickers',
        'location' => 'ubicaciones',
        'contacts' => 'contactos',
    ];

    public function __construct(
        public readonly string $from,
        public readonly string $type,
    ) {}

    public function handle(IdentityResolver $resolver, MessagingChannel $client): void
    {
        $user = $resolver->resolveUser('whatsapp', $this->from);

        if ($user === null) {
            $client->sendText(
                $this->from,
                'Hola 👋 Para usar el asistente de Nexolú por WhatsApp, primero vincula '
                .'este numero desde el POS: entra a tu cuenta, ve al Asistente y elige '
                .'"Asistente por WhatsApp".',
                null,
                'ai_chat_onboarding',
            );

            return;
        }

        $label = self::LABELS[$this->type] ?? 'ese tipo de mensaje';

        $client->sendText($this->from, "Por ahora no puedo leer {$label} 🙏 Escríbeme el pedido en texto y te ayudo.", $user->business_id, 'ai_chat');
    }
}
