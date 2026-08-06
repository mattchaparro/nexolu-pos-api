<?php

namespace App\Providers;

use App\Listeners\LogSentEmail;
use App\Services\WhatsApp\Contracts\ChannelOtpSender;
use App\Services\WhatsApp\LogChannelOtpSender;
use App\Services\WhatsApp\WhatsAppCloudClient;
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
        // Sin credenciales de WhatsApp (local/testing), el OTP de vinculacion
        // cae a un emisor que solo loguea - nunca a un intento de envio real
        // que va a fallar igual.
        $this->app->bind(ChannelOtpSender::class, function ($app) {
            return $app->make(WhatsAppCloudClient::class)->isConfigured()
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
