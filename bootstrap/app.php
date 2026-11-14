<?php

use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Middleware\EnsureBusinessAdmin;
use App\Http\Middleware\EnsureBusinessCanAccessPurchases;
use App\Http\Middleware\EnsureBusinessFeatureEnabled;
use App\Http\Middleware\EnsureBusinessPermission;
use App\Http\Middleware\EnsureCashShiftOpenForSales;
use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureValidIaCoreKey;
use App\Http\Middleware\EnsureValidLegacyAdminKey;
use App\Http\Middleware\RecordApiMetrics;
use App\Http\Middleware\ResolveBranch;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Middleware\SentryBusinessContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-only application: there is no "login" web route to
        // redirect guests to, so unauthenticated requests must always fall
        // through to a JSON 401 instead of Laravel's default HTML redirect.
        $middleware->redirectGuestsTo(fn () => null);

        // Primero del grupo api: mide la peticion completa (ver RecordApiMetrics).
        $middleware->api(prepend: [RecordApiMetrics::class]);

        $middleware->alias([
            'feature' => EnsureBusinessFeatureEnabled::class,
            'can-access-purchases' => EnsureBusinessCanAccessPurchases::class,
            'superadmin' => EnsureSuperAdmin::class,
            'ia-core.key' => EnsureValidIaCoreKey::class,
            'legacy.admin-key' => EnsureValidLegacyAdminKey::class,
            'permission' => EnsureBusinessPermission::class,
            'business-admin' => EnsureBusinessAdmin::class,
            'cash-shift.required-for-sales' => EnsureCashShiftOpenForSales::class,
            'sentry.context' => SentryBusinessContext::class,
            'storefront.tenant' => ResolveStorefrontTenant::class,
            'branch.context' => ResolveBranch::class,
            'subscription.active' => EnsureSubscriptionActive::class,
            'print.agent' => AuthenticatePrintAgent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // El 429 lo lee un comprador o un cajero, no un desarrollador: el
        // default del framework es "Too Many Attempts." (mismo texto que los
        // limitadores de login en AppServiceProvider).
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*')
            ? response()->json(
                ['message' => 'Demasiados intentos. Espera un momento y vuelve a intentar.'],
                429,
                $e->getHeaders(),
            )
            : null);

        Integration::handles($exceptions);
    })->create();
