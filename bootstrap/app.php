<?php

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
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON — this is a pure API, no Blade views needed.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

            return response()->json([
                'error'   => true,
                'message' => $e->getMessage() ?: 'Server Error',
                'code'    => $status,
            ], $status);
        });
    })->create();
