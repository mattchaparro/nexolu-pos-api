<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda una difusion por WhatsApp al numero de cada negocio al que va, con
 * la plantilla de novedades (config services.whatsapp.templates.novedades):
 * {{1}} el negocio, {{2}} el texto de la difusion, y un boton a Nexolu POS.
 *
 * En cola y uno por uno: con muchos negocios no se bloquea la pantalla del
 * superadmin, y un numero malo no tumba a los demas. El resultado queda
 * contado en la difusion y cada envio en la bitacora de Comunicaciones
 * (tipo 'difusion').
 */
class SendAnnouncementWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $announcementId) {}

    public function handle(MessagingChannel $channel): void
    {
        $announcement = Announcement::find($this->announcementId);
        $template = (string) config('services.whatsapp.templates.novedades.name');

        if (! $announcement || $template === '') {
            return;
        }

        // Meta rechaza saltos de linea y tabs dentro de una variable.
        $text = trim((string) preg_replace('/\s+/', ' ', $announcement->title.'. '.$announcement->body));
        $sent = 0;
        $failed = 0;

        foreach ($announcement->targetBusinesses() as $business) {
            $phone = $business->whatsapp_number ? ChannelPhone::normalize($business->whatsapp_number) : null;
            if ($phone === null) {
                $failed++;

                continue;
            }

            $ok = $channel->sendTemplate(
                $phone,
                $template,
                (string) config('services.whatsapp.templates.novedades.lang', 'es_CO'),
                [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string) $business->name],
                        ['type' => 'text', 'text' => mb_substr($text, 0, 900)],
                    ],
                ]],
                (int) $business->id,
                'difusion',
            );

            $ok ? $sent++ : $failed++;
        }

        $announcement->forceFill(['whatsapp_sent' => $sent, 'whatsapp_failed' => $failed])->save();

        try {
            Log::info('announcement.whatsapp', ['id' => $announcement->id, 'sent' => $sent, 'failed' => $failed]);
        } catch (\Throwable) {
        }
    }
}
