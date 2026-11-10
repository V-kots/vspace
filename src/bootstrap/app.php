<?php

use App\Http\Middleware\AssignRequestId;
use App\Support\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        // API не делает redirect на web-страницу /login: неавторизованные
        // запросы должны получать JSON 401 с request_id/code.
        $middleware->redirectGuestsTo(static fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('login', 'user/*', 'post/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('post/*') || $request->expectsJson()) {
                return ApiError::unauthorized($request);
            }

            return null;
        });

        $exceptions->context(function () {
            $request = request();

            if ($request === null) {
                return [];
            }

            return [
                'request_id' => $request->attributes->get('request_id')
                    ?? $request->headers->get('X-Request-Id'),
            ];
        });
    })->create();
