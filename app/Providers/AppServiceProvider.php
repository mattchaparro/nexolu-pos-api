<?php

namespace App\Providers;

use App\Listeners\LogSentEmail;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Messaging\Contracts\MessagingCostReporter;
use App\Services\WhatsApp\Contracts\ChannelOtpSender;
use App\Services\WhatsApp\LogChannelOtpSender;
use App\Services\WhatsApp\LoggingMessagingChannel;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Services\WhatsApp\NexoluCommsCostReporter;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppCostReporter;
use App\Services\WhatsApp\WhatsAppOtpSender;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Unico punto que sabe cual proveedor de mensajeria esta activo -
        // config('services.comms_core.driver'): 'whatsapp_direct' (Meta
        // directo, WhatsAppCloudClient, valor de hoy) o 'nexolu_comms' (via
        // Nexolu Communications, NexoluCommsChannel). Todo lo demas (jobs,
        // comandos, PlatformFinanceService) depende de las interfaces, no de
        // App\Services\WhatsApp\* directamente, asi que el cambio de
        // proveedor en produccion es solo tocar MESSAGING_DRIVER. Envuelto en
        // LoggingMessagingChannel para que todo envio quede en whatsapp_logs
        // (pantalla de Comunicaciones de SuperAdmin) sin importar cual de
        // los dos proveedores este activo.
        $this->app->bind(MessagingChannel::class, fn () => new LoggingMessagingChannel(
            config('services.comms_core.driver') === 'nexolu_comms'
                ? $this->app->make(NexoluCommsChannel::class)
                : $this->app->make(WhatsAppCloudClient::class)
        ));

        $this->app->bind(MessagingCostReporter::class, fn () => config('services.comms_core.driver') === 'nexolu_comms'
            ? $this->app->make(NexoluCommsCostReporter::class)
            : $this->app->make(WhatsAppCostReporter::class));

        // Sin credenciales de WhatsApp (local/testing), el OTP de vinculacion
        // cae a un emisor que solo loguea - nunca a un intento de envio real
        // que va a fallar igual.
        $this->app->bind(ChannelOtpSender::class, function ($app) {
            return $app->make(MessagingChannel::class)->isConfigured()
                ? $app->make(WhatsAppOtpSender::class)
                : $app->make(LogChannelOtpSender::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This API returns resources at the response root (no "data" envelope),
        // matching the {token, user} shape already used by the login endpoint.
        JsonResource::withoutWrapping();

        // Historial de correos completo por diseno: cualquier email que la
        // app envie queda en email_logs sin que el codigo que lo dispara
        // tenga que acordarse de loguearlo.
        Event::listen(MessageSent::class, LogSentEmail::class);

        // El producto es exclusivamente para Colombia: translatedFormat()
        // (usado en fechas de correos, recordatorios, etc.) debe salir en
        // espanol sin importar APP_LOCALE, que se queda en "en" para los
        // mensajes de validacion del framework.
        Carbon::setLocale('es');
    }
}
