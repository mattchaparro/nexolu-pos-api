<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\InboundMessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook publico de Nexolu Communications (servicio Python aparte, repo
 * nexolu-comms-api): reemplaza a WhatsappWebhookController el dia que el
 * numero de WhatsApp se mueva a ese servicio (ver
 * config/services.php::comms_core.driver). Comms verifica por su cuenta que
 * el evento vino de Meta y lo reenvia INTACTO (mismo shape que Meta
 * mandaria directo), firmado con HMAC propio - por eso este controller
 * reusa InboundMessageDispatcher tal cual lo usa WhatsappWebhookController,
 * sin reinterpretar nada.
 */
class NexoluCommsWebhookController extends Controller
{
    public function __construct(private InboundMessageDispatcher $dispatcher) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('nexolu_comms.webhook: firma invalida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $this->dispatcher->dispatch($request->input('entry', []));

        return response()->json(['ok' => true]);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.comms_core.webhook_secret');
        $timestamp = (string) $request->header('X-Nexolu-Timestamp');
        $signature = (string) $request->header('X-Nexolu-Signature');

        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
