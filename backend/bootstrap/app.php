<?php

use App\Exceptions\TenantMismatchException;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent error envelope for the whole API, per docs/PROJECT_CONTEXT.md:
        // {"error":{"code":"...","message":"...","details":{}}}
        $envelope = function (int $status, string $code, string $message, array $details = []) {
            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => (object) $details,
                ],
            ], $status);
        };

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(401, 'unauthenticated', 'Authentication required.');
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(403, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.');
            }
        });

        // Laravel's Handler::prepareException() converts AuthorizationException (e.g. from
        // Gate::authorize()) into this Symfony exception BEFORE render callbacks run, so the
        // AuthorizationException-specific renderer above never actually matches in practice —
        // this is the one that fires. Kept both so the intent is obvious and it still works if
        // that internal conversion behaviour ever changes.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(403, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.');
            }
        });

        $exceptions->render(function (TenantMismatchException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(403, 'tenant_mismatch', 'This resource does not belong to the current organization.');
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(422, 'validation_failed', 'The given data was invalid.', $e->errors());
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(404, 'not_found', 'The requested resource was not found.');
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(404, 'not_found', 'The requested resource was not found.');
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $envelope(429, 'rate_limited', 'Too many requests.');
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($envelope) {
            if (($request->is('api/*') || $request->expectsJson()) && $e->getStatusCode() >= 400) {
                return $envelope($e->getStatusCode(), 'http_error', $e->getMessage() ?: HttpResponse::$statusTexts[$e->getStatusCode()] ?? 'Error');
            }
        });
    })->create();
