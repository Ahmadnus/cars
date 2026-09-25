<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Mobile clients authorise socket subscriptions with their bearer
        // token, so the endpoint sits on the API middleware group rather than
        // the default web one.
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            EnsureUserIsActive::class,
        ]);

        // Sanctum's stateful middleware lets the dashboard's own JS call the
        // API with the session cookie, while mobile clients use bearer tokens.
        $middleware->api(prepend: [
            Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One JSON error envelope for every API consumer, so the Flutter
        // clients parse failures the same way regardless of cause. Nothing
        // internal (stack traces, SQL) is ever included.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof Illuminate\Validation\ValidationException => response()->json([
                    'success' => false,
                    'message' => 'البيانات المدخلة غير صحيحة.',
                    'errors' => $e->errors(),
                ], 422),

                $e instanceof Illuminate\Auth\AuthenticationException => response()->json([
                    'success' => false,
                    'message' => 'يجب تسجيل الدخول للمتابعة.',
                    'errors' => (object) [],
                ], 401),

                $e instanceof Illuminate\Auth\Access\AuthorizationException,
                $e instanceof Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException => response()->json([
                    'success' => false,
                    'message' => 'لا تملك صلاحية تنفيذ هذه العملية.',
                    'errors' => (object) [],
                ], 403),

                $e instanceof Illuminate\Database\Eloquent\ModelNotFoundException,
                $e instanceof Symfony\Component\HttpKernel\Exception\NotFoundHttpException => response()->json([
                    'success' => false,
                    'message' => 'العنصر المطلوب غير موجود.',
                    'errors' => (object) [],
                ], 404),

                $e instanceof Illuminate\Http\Exceptions\ThrottleRequestsException => response()->json([
                    'success' => false,
                    'message' => 'عدد كبير من المحاولات. يرجى المحاولة لاحقاً.',
                    'errors' => (object) [],
                ], 429),

                // Catch-all for abort() and any other HTTP exception, so every
                // API failure carries the same envelope the clients parse.
                // Nothing internal is exposed: unknown statuses get a generic
                // Arabic message rather than the exception text.
                $e instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => response()->json([
                    'success' => false,
                    'message' => match ($e->getStatusCode()) {
                        401 => 'يجب تسجيل الدخول للمتابعة.',
                        403 => 'لا تملك صلاحية تنفيذ هذه العملية.',
                        404 => 'العنصر المطلوب غير موجود.',
                        409 => 'لا يمكن تنفيذ العملية بسبب تعارض في البيانات.',
                        422 => 'البيانات المدخلة غير صحيحة.',
                        429 => 'عدد كبير من المحاولات. يرجى المحاولة لاحقاً.',
                        default => 'تعذّر تنفيذ الطلب.',
                    },
                    'errors' => (object) [],
                ], $e->getStatusCode()),

                default => null,
            };
        });
    })->create();
