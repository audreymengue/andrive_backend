<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ParametrageSecurity;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;

class TemporaryPasswordController extends Controller
{
    public function __invoke(int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $secret = app(PasswordService::class)->temporaryPasswordForAuthorizedAdministrator($user);
        if ($secret === null) {
            app(ParametrageSecurity::class)->fail(409, 'TEMPORARY_PASSWORD_UNAVAILABLE', 'Le mot de passe temporaire est indisponible.');
        }

        return response()->json(['message' => 'Mot de passe temporaire révélé.', 'data' => ['temporary_password' => $secret, 'password_generation' => $user->password_generation]])->header('Cache-Control', 'no-store');
    }
}
