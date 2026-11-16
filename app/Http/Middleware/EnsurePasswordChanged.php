<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Vous devez modifier votre mot de passe avant de poursuivre.',
                ...(($request->route('treatment_api') || $request->route('resource_profile_kind')) ? ['code' => 'PASSWORD_CHANGE_REQUIRED'] : []),
            ], 403);
        }

        return $next($request);
    }
}
