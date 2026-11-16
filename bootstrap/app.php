<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ProtectParametrage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'parametrage' => ProtectParametrage::class,
            'password.changed' => EnsurePasswordChanged::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Non authentifié.', ...(($request->route('organisation_kind') || $request->route('treatment_api') || $request->route('resource_profile_kind') || $request->route('transport_api')) ? ['code' => 'UNAUTHENTICATED'] : [])], 401);
        });

        $exceptions->render(function (AuthorizationException|UnauthorizedException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Vous n’avez pas la permission d’effectuer cette action.', ...(($request->route('treatment_api') || $request->route('resource_profile_kind') || $request->route('transport_api')) ? ['code' => 'FORBIDDEN'] : [])], 403);
        });

        $exceptions->render(function (ValidationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Les données fournies sont invalides.',
                'errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $modelNotFoundException = $exception instanceof ModelNotFoundException
                ? $exception
                : $exception->getPrevious();

            $message = $modelNotFoundException instanceof ModelNotFoundException && $modelNotFoundException->getModel() === User::class
                ? 'L’utilisateur demandé n’existe pas.'
                : 'Ressource introuvable.';

            return response()->json(['message' => $message, ...($request->route('organisation_kind') ? ['code' => 'NOT_FOUND'] : [])], 404);
        });
    })->create();
