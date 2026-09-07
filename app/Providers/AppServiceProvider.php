<?php

namespace App\Providers;

use App\Listeners\LogSentEmail;
use App\Mail\Transport\CommsTransport;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        // El correo tambien sale por el Communications Core, no por SMTP:
        // un solo servicio con las credenciales y un solo lugar donde ver
        // que se envio. Va en boot() y no en register(): el MailManager
        // todavia no existe cuando corre register().
        //
        // Se registra siempre; cual se usa lo decide MAIL_MAILER (en local
        // sigue siendo `log`).
        Mail::extend('comms', fn (array $config) => new CommsTransport);

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

        $this->registerAuthRateLimiters();
    }

    /**
     * Limites de las puertas publicas de autenticacion.
     *
     * Hasta 2026-09-07 /login, /register, /forgot-password y /reset-password
     * no tenian ninguno: se podian probar contraseñas a la velocidad que
     * diera la red. Son dos riesgos en uno - fuerza bruta de credenciales, y
     * agotar el servidor, porque cada intento corre Hash::check (bcrypt), que
     * es caro A PROPOSITO. En un droplet de 1 core eso tumba el POS de un
     * negocio en vivo sin necesidad de volumen de red.
     *
     * Los mensajes van en espanol: el 429 le llega al cajero, no a un
     * desarrollador (el default del framework es "Too Many Attempts.").
     */
    private function registerAuthRateLimiters(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json([
            'message' => 'Demasiados intentos. Espera un momento y vuelve a intentar.',
        ], 429, $headers);

        RateLimiter::for('login', function (Request $request) use ($tooMany) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                // Por credencial: frena la fuerza bruta contra UNA cuenta.
                // El email va junto a la IP para que un atacante no pueda
                // dejar bloqueada la cuenta de un negocio ajeno solo con
                // fallar adrede (eso seria un DoS contra ese usuario).
                Limit::perMinute(5)->by($email.'|'.$request->ip())->response($tooMany),
                // Por IP: frena el barrido de MUCHAS cuentas desde un mismo
                // origen. Holgado a proposito: varios cajeros de un negocio
                // pueden compartir la IP publica del local.
                Limit::perMinute(30)->by($request->ip())->response($tooMany),
            ];
        });

        // Registro: crear negocios en masa no tiene uso legitimo a este
        // ritmo, y cada alta escribe varias tablas.
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)
            ->by($request->ip())
            ->response($tooMany));

        // Recuperacion: el limite por email evita usar la app para bombardear
        // el correo de una persona; el de IP, para barrer muchos.
        RateLimiter::for('password-recovery', function (Request $request) use ($tooMany) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perHour(5)->by($email)->response($tooMany),
                Limit::perHour(20)->by($request->ip())->response($tooMany),
            ];
        });
    }
}
