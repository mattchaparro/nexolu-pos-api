<?php

namespace App\Jobs;

use App\Models\ExpenseType;
use App\Models\User;
use App\Services\AiChatService;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\WhatsApp\IdentityResolver;
use App\Support\AiTenantContext;
use App\Support\WhatsAppTextFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Procesa un mensaje de texto entrante de WhatsApp: resuelve identidad,
 * proxea al IA Core (mismo AiChatService que usa el chat web, canal
 * 'whatsapp') y responde por WhatsApp Cloud API.
 *
 * Fase 2: si el IA Core devuelve un borrador de gasto pendiente, se manda un
 * WhatsApp Flow (formulario nativo) en vez de solo avisar por texto - ver
 * sendExpenseFlow(). Sin `services.whatsapp.flows.gasto.id` configurado (el
 * Flow real todavia no esta creado en Meta para esta API), cae de vuelta al
 * aviso de texto ("confirmalo desde el POS"), igual que antes.
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

    public function handle(IdentityResolver $resolver, AiChatService $chat, MessagingChannel $client): void
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
            $context = AiTenantContext::forUser($user, self::CHANNEL);

            $result = $chat->send(self::AGENT, $this->text, $context, $conversationId);

            if (! empty($result['conversation_id'])) {
                Cache::put($conversationCacheKey, $result['conversation_id'], now()->addHours(self::CONVERSATION_CACHE_TTL_HOURS));
            }

            $flowSent = false;
            foreach ($result['drafts'] ?? [] as $draft) {
                if (($draft['tool_type'] ?? null) === 'crear_gasto') {
                    $flowSent = $this->sendExpenseFlow($client, $user, $draft) || $flowSent;
                }
            }

            // El texto del modelo dice "revisalo y confirmalo abajo", pensado
            // para la tarjeta del chat web. Si ya se mando el Flow, ese texto
            // es ruido duplicado -- el Flow ES el "abajo".
            if (! $flowSent) {
                $message = WhatsAppTextFormatter::fromMarkdown((string) ($result['text'] ?? ''));

                if (! empty($result['drafts'])) {
                    $message .= "\n\n📝 Tienes un borrador pendiente de confirmar. Por ahora confirmalo desde el POS.";
                }

                $client->sendText($this->from, $message);
            }
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

    /**
     * Manda el Flow de confirmacion de gasto con los datos del borrador
     * prellenados. flow_token es directamente el id del borrador en el IA
     * Core - ver ProcessWhatsAppFlowReply.
     *
     * OJO: el screen/campos de aca son el mejor esfuerzo contra los
     * argumentos reales de CreateExpenseCapability (concepto/monto/
     * tipo_gasto/fecha - `tipo_gasto` es un NOMBRE libre, no un id, a
     * diferencia del Flow de legacy que usaba `type_id` numerico). Falta
     * verificarlos contra el Flow real una vez exista
     * `WHATSAPP_FLOW_GASTO_ID` en produccion.
     *
     * @param  array<string, mixed>  $draft  un item de $result['drafts'] (id, tool_type, summary, fields, values)
     */
    private function sendExpenseFlow(MessagingChannel $client, User $user, array $draft): bool
    {
        $flow = config('services.whatsapp.flows.gasto');

        if (empty($flow['id'])) {
            return false;
        }

        $values = $draft['values'] ?? [];

        $types = ExpenseType::where(fn ($q) => $q->where('business_id', $user->business_id)->orWhereNull('business_id'))
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name) => ['id' => $name, 'title' => $name])
            ->values()
            ->all();

        $date = Carbon::parse($values['fecha'] ?? now()->toDateString());

        $data = [
            'concepto' => (string) ($values['concepto'] ?? ''),
            'monto' => (float) ($values['monto'] ?? 0),
            'fecha' => $date->toDateString(),
            'fecha_label' => 'Fecha: '.$date->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'tipo_gasto' => (string) ($values['tipo_gasto'] ?? ($types[0]['id'] ?? 'Varios')),
            'tipos' => $types !== [] ? $types : [['id' => 'Varios', 'title' => 'Varios']],
        ];

        return $client->sendFlow(
            $this->from,
            $flow['id'],
            $flow['screen'] ?? 'GASTO',
            $flow['body'] ?? 'Confirma los datos del gasto:',
            $flow['cta'] ?? 'Confirmar',
            $data,
            (string) $draft['id'],
        );
    }

    private function onboardingMessage(): string
    {
        return 'Hola 👋 Para usar el asistente de Nexolú por WhatsApp, primero vincula '
            .'este numero desde el POS: entra a tu cuenta, ve al Asistente y elige '
            .'"Asistente por WhatsApp".';
    }
}
