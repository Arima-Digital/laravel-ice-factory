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

        // Exclude auth & admin routes from CSRF verification (API endpoints)
        $middleware->validateCsrfTokens(except: [
            'auth/*',
            'admin/*',
        ]);

        // Register middleware aliases
        $middleware->alias([
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle authentication exception for API requests
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('admin/*') || $request->is('auth/*')) {
                return response()->json([
                    'message' => 'Unauthenticated',
                    'error' => 'Missing or invalid API token. Please login first.',
                ], 401);
            }
        });
    })->create();
