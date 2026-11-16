<?php

namespace App\Http\Middleware;

use App\Services\ParametrageSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectParametrage
{
    public function handle(Request $request, Closure $next, string $permission, string $kind): Response
    {
        return app(ParametrageSecurity::class)->handle($request, $next, $permission, $kind);
    }
}
