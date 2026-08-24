<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBusinessNotificationsRequest;
use App\Http\Requests\Api\V1\UpdateBusinessRequest;
use App\Http\Resources\Api\V1\BusinessResource;
use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Services\WhatsApp\ChannelLinkService;
use App\Support\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BusinessController extends Controller
{
    public function __construct(private ChannelLinkService $channelLinks) {}

    public function show(Request $request): BusinessResource
    {
        return new BusinessResource($this->currentBusiness($request));
    }

    public function update(UpdateBusinessRequest $request): BusinessResource
    {
        $business = $this->currentBusiness($request);
        $business->update($this->buildPayload($request, $business));

        return new BusinessResource($business->fresh());
    }

    /**
     * Guarda las preferencias de alertas por WhatsApp del negocio. Solo se
     * permite si el usuario que las guarda tiene su WhatsApp vinculado -
     * una alerta sin numero al que llegar no tiene sentido (mismo requisito
     * que Admin\SettingsController::updateNotifications() del legacy).
     */
    public function updateNotifications(UpdateBusinessNotificationsRequest $request): JsonResponse
    {
        if ($this->channelLinks->identityFor($request->user(), AiChannelIdentity::CHANNEL_WHATSAPP) === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Primero vincula tu WhatsApp para activar las alertas.',
            ], 422);
        }

        $incoming = $request->validated('preferences', []);
        $preferences = [];
        foreach (NotificationTypes::keys() as $key) {
            $preferences[$key] = (bool) ($incoming[$key] ?? false);
        }

        // Solo se guardan overrides para los tipos schedulable - una hora
        // vacia/ausente deja el default de la plataforma aplicandose solo
        // (ver Business::notificationHour()), no se persiste "00:00" ni
        // nada para lo que el negocio no toco.
        $incomingSchedule = $request->validated('schedule', []);
        $schedule = [];
        foreach (NotificationTypes::SCHEDULABLE as $key) {
            if (! empty($incomingSchedule[$key])) {
                $schedule[$key] = $incomingSchedule[$key];
            }
        }

        $this->currentBusiness($request)->update([
            'notification_preferences' => $preferences,
            'notification_schedule' => $schedule,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Reactiva antes de tiempo las alertas de inventario bajo que el dueño
     * silencio desde el enlace del correo (ver NotificationSnoozeController).
     */
    public function clearLowStockSnooze(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_business_owner || $user?->hasRole('admin'), 403);

        $this->currentBusiness($request)->update(['low_stock_snoozed_until' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * email_header_color/email_footer_text no son columnas propias: se
     * guardan dentro de email_config (mismas claves 'header_color'/
     * 'footer_text' que ya consumen las plantillas en
     * resources/views/emails/partials/footer.blade.php), igual que en
     * Admin\SettingsController::store() del legacy.
     */
    private function buildPayload(UpdateBusinessRequest $request, Business $business): array
    {
        $data = $request->validated();

        if (array_key_exists('email_header_color', $data) || array_key_exists('email_footer_text', $data)) {
            $emailConfig = $business->email_config ?? [];

            if (array_key_exists('email_header_color', $data)) {
                $emailConfig['header_color'] = $data['email_header_color'];
            }
            if (array_key_exists('email_footer_text', $data)) {
                $emailConfig['footer_text'] = $data['email_footer_text'];
            }

            $data['email_config'] = $emailConfig;
            unset($data['email_header_color'], $data['email_footer_text']);
        }

        return $data;
    }

    private function currentBusiness(Request $request): Business
    {
        $business = $request->user()?->business;

        if (! $business) {
            throw new NotFoundHttpException('El usuario autenticado no tiene un negocio asociado.');
        }

        return $business;
    }
}
