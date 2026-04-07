<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $code = $e instanceof HttpException ? $e->getStatusCode() : 500;
                $message = $e->getMessage();
                if ($code === 500 && !config('app.debug')) {
                    $message = 'Server error.';
                }
                return response()->json([
                    'message' => $message,
                    'exception' => config('app.debug') ? get_class($e) : null,
                ], $code);
            }
        });
    })->create();
