<?php

use App\Exceptions\BusinessRule;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['active' => EnsureActiveUser::class, 'admin' => RequireAdmin::class]);
        $trustedProxies = env('TRUSTED_PROXIES', '127.0.0.1,::1');
        $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_filter(array_map('trim', explode(',', $trustedProxies))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontReport([BusinessRule::class]);
        $exceptions->render(fn (BusinessRule $exception) => response()->json(['error' => $exception->getMessage()], $exception->status));
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => collect($exception->errors())->flatten()->first() ?? 'Revisa los datos ingresados.', 'errors' => $exception->errors()], 400);
            }

            return null;
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return $request->is('api/*') ? response()->json(['error' => 'Inicia sesión para continuar.'], 401) : null;
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('sanctum/*')) {
                return null;
            }
            $status = $exception->getStatusCode();
            $message = match ($status) {
                404 => 'El registro o la ruta no existe.',
                419 => 'La sesión cambió o venció. Recarga la página e inicia sesión de nuevo.',
                429 => 'Demasiados intentos. Espera un minuto antes de volver a intentar.',
                403 => 'No tienes permisos para esta acción.',
                default => 'No fue posible completar la solicitud.',
            };

            return response()->json(['error' => $message], $status, $exception->getHeaders());
        });
    })->create();
