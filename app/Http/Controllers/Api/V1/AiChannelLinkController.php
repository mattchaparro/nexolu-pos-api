<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiChannelIdentity;
use App\Services\WhatsApp\ChannelLinkService;
use App\Services\WhatsApp\Exceptions\ChannelLinkException;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiChannelLinkController extends Controller
{
    private const CHANNEL = AiChannelIdentity::CHANNEL_WHATSAPP;

    public function __construct(private ChannelLinkService $links) {}

    /** Pide el OTP para el numero que el usuario escribio. */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        try {
            $challenge = $this->links->start($request->user(), self::CHANNEL, $validated['phone']);

            return response()->json([
                'ok' => true,
                'expires_at' => $challenge->expires_at->toIso8601String(),
                'message' => 'Te enviamos un codigo por WhatsApp. Escribelo para confirmar.',
            ]);
        } catch (ChannelLinkException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Confirma el OTP y deja el numero vinculado. */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:6']]);

        try {
            $identity = $this->links->confirm($request->user(), self::CHANNEL, $validated['code']);
            $this->sendWelcomeTemplate($identity->external_id, $request->user()->business?->name ?? '');

            return response()->json([
                'ok' => true,
                'message' => 'Listo, tu WhatsApp quedo vinculado.',
                'partial_number' => '••••••'.substr($identity->external_id, -4),
            ]);
        } catch (ChannelLinkException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Deshace el vinculo. */
    public function unlink(Request $request): JsonResponse
    {
        $this->links->unlink($request->user(), self::CHANNEL);

        return response()->json(['ok' => true, 'message' => 'Tu WhatsApp quedo desvinculado.']);
    }

    private function sendWelcomeTemplate(string $externalId, string $businessName): void
    {
        $template = config('services.whatsapp.templates.welcome');
        if (empty($template['name'])) {
            return;
        }

        $components = [];
        $var = $template['var'] ?? null;
        if ($var === 'header' || $var === 'body') {
            $name = Str::limit($businessName, (int) ($template['var_max'] ?? 60), '');
            $components[] = [
                'type' => $var,
                'parameters' => [['type' => 'text', 'text' => $name !== '' ? $name : 'tu negocio']],
            ];
        }

        app(WhatsAppCloudClient::class)->sendTemplate(
            $externalId, (string) $template['name'], (string) ($template['lang'] ?? 'es'), $components,
        );
    }
}
