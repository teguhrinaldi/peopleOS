<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
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
        // Authorization must run BEFORE route-model binding, otherwise a 404 vs 403 reveals which ids exist.
        foreach ([EnsureUserIsActive::class, EnsureAdminAccess::class, RequirePermission::class] as $guard) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $guard);
        }

        // Language: header/company default for every request, and again after auth so a user's saved preference wins.
        $middleware->appendToGroup('web', SetLocale::class);
        $middleware->appendToGroup('api', SetLocale::class);

        $middleware->alias([
            'locale' => SetLocale::class,
            'active' => EnsureUserIsActive::class,
            'permission' => RequirePermission::class,
            'admin.access' => EnsureAdminAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $fail = fn (string $code, string $message, int $status, array $details = []) => response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
        ], $status);

        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(fn (ApiException $e, Request $r) => $isApi($r)
            ? $fail($e->errorCode, $e->getMessage(), $e->status, $e->details) : null);

        // Web admin: a domain rule violation becomes a friendly flash message instead of a JSON body.
        $exceptions->render(fn (ApiException $e, Request $r) => ! $isApi($r)
            ? back()->withInput()->with('error', $e->getMessage()) : null);

        $exceptions->render(fn (ValidationException $e, Request $r) => $isApi($r)
            ? $fail('VALIDATION_ERROR', 'The given data was invalid.', 422, $e->errors()) : null);

        $exceptions->render(fn (AuthenticationException $e, Request $r) => $isApi($r)
            ? $fail('UNAUTHENTICATED', 'Authentication is required.', 401) : null);

        $exceptions->render(fn (AuthorizationException $e, Request $r) => $isApi($r)
            ? $fail('FORBIDDEN', 'You do not have permission to perform this action.', 403) : null);

        $exceptions->render(fn (ModelNotFoundException $e, Request $r) => $isApi($r)
            ? $fail('NOT_FOUND', 'The requested resource was not found.', 404) : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $isApi($r)
            ? $fail('NOT_FOUND', 'The requested resource was not found.', 404) : null);

        $exceptions->render(fn (TooManyRequestsHttpException $e, Request $r) => $isApi($r)
            ? $fail('TOO_MANY_REQUESTS', 'Too many requests. Please slow down.', 429, ['retry_after_seconds' => (int) ($e->getHeaders()['Retry-After'] ?? 60)]) : null);

        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }
            $status = $e->getStatusCode();

            return match ($status) {
                403 => $fail('FORBIDDEN', 'You do not have permission to perform this action.', 403),
                405 => $fail('METHOD_NOT_ALLOWED', 'This method is not allowed.', 405),
                429 => $fail('TOO_MANY_REQUESTS', 'Too many requests. Please slow down.', 429),
                default => $status >= 500 ? $fail('SERVER_ERROR', 'Something went wrong on our side.', $status) : $fail('HTTP_ERROR', $e->getMessage() ?: 'Request failed.', $status),
            };
        });

        // Anything unexpected: never leak internals unless debugging locally.
        $exceptions->render(fn (Throwable $e, Request $r) => $isApi($r) && ! config('app.debug')
            ? $fail('SERVER_ERROR', 'Something went wrong on our side.', 500) : null);
    })->create();
