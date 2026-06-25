<?php

use App\Exceptions\ExternalServiceException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ExternalServiceException $e) {
            return response()->json([
                'error' => array_filter([
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => $e->details === [] ? null : $e->details,
                ], fn ($v) => $v !== null),
            ], $e->httpStatus);
        });

        $exceptions->render(function (ConnectionException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'UPSTREAM_TIMEOUT',
                    'message' => 'Tempo limite ao contatar um serviço externo. Tente novamente.',
                ],
            ], 504);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson()) || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => config('app.debug') ? $e->getMessage() : 'Erro interno ao processar a solicitação.',
                ],
            ], 500);
        });
    })->create();
