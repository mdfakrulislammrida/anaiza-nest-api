<?php

use App\Http\Middleware\RejectFormBots;
use App\Http\Middleware\ResolveClientIp;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The product feeds: plain GET routes on the API's own domain. No session, cookies or CSRF, since an ad platform fetches them.
            Route::middleware('throttle:120,1')->group(base_path('routes/feeds.php'));

            // Links in emails (one-click unsubscribe): opened without a session, so outside the web group.
            Route::middleware('throttle:60,1')->group(base_path('routes/mailbox.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First of all, so everything after it (trusted proxies, every limiter, the stored client_ip) sees the visitor's address.
        $middleware->prepend(ResolveClientIp::class);

        // Response headers on everything, including error pages.
        $middleware->append(SecurityHeaders::class);

        // A general ceiling for the whole API (the named limiter "api"), under the tighter ones on the routes that write.
        $middleware->throttleApi('api');

        $middleware->alias(['form-proof' => RejectFormBots::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Said in the shop's voice, once, for every limiter.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'That is a lot of tries in a short time. Please wait a minute and try again.',
                ], 429, $e->getHeaders());
            }

            return null;
        });

        // A missing record is "not found"; the model class behind it is nobody's business.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'We could not find that.'], 404);
            }

            return null;
        });
    })->create();
