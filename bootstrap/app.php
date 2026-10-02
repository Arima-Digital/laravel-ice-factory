<?php

use App\Http\Middleware\CheckRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // Register middleware aliases
        $middleware->alias([
            'role' => CheckRole::class,
        ]);

        // Laravel points the redirect for an unauthenticated request at route
        // 'login' by default. This app has no such route, so any request that is
        // not asking for JSON died on "Route [login] not defined" while building
        // the exception, and surfaced as a 500 instead of a 401. Nothing here is
        // server-rendered, so there is nowhere to send a browser anyway.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An /api/* path always answers JSON, whatever the client put in Accept.
        // Without this, a curl or a Swagger "Try it out" that sends no Accept
        // header gets an HTML error page from a JSON API.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'error' => 'Missing or invalid API token. Please login first.',
                ], 401);
            }
        });
    })->create();
