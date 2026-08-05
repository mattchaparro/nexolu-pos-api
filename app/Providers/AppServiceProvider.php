<?php

namespace App\Providers;

use App\Listeners\LogSentEmail;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
