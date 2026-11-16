<?php

namespace App\Http\Middleware;

use App\Services\ParametrageSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SerializeAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/login', 'api/me/password')) {
            if ($request->user()?->fresh()?->statut === 'INACTIF') {
                return response()->json(['message' => 'Compte inactif.', 'code' => 'FORBIDDEN'], 403);
            }

            return $next($request);
        }

        return DB::transaction(function () use ($request, $next): Response {
            app(ParametrageSecurity::class)->lock();
            if ($user = $request->user()) {
                $token = $user->currentAccessToken();
                if ($token instanceof PersonalAccessToken && ! $token->newQuery()->whereKey($token->id)->exists()) {
                    abort(401, 'Session révoquée.');
                }
                $user->refresh();
                if ($user->statut !== 'ACTIF') {
                    abort(403, 'Compte inactif.');
                }
            }

            return $next($request);
        }, 3);
    }
}
