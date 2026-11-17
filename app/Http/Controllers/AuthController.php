<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private PasswordService $passwordService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->login($data['email'], $data['password']);

        if ($result['reason'] === 'invalid_credentials') {
            return response()->json(['message' => 'Les identifiants fournis sont incorrects.', 'code' => 'UNAUTHENTICATED'], 401);
        }

        if ($result['reason'] === 'inactive') {
            return response()->json(['message' => 'Ce compte est désactivé.', 'code' => 'FORBIDDEN'], 403);
        }

        /** @var User $user */
        $user = $result['user'];
        $token = $this->authService->createToken($user);

        return response()->json([
            'message' => 'Connexion réussie.',
            'data' => [
                'token' => $token,
                'must_change_password' => $user->must_change_password,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'message' => 'Utilisateur connecté récupéré avec succès.',
            'data' => new UserResource($user->load('roles.permissions')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authService->logout($user);

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    public function updatePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (! $user->must_change_password && ! $this->passwordService->matches($user, $data['current_password'])) {
            return response()->json(['message' => 'L’ancien mot de passe est incorrect.', 'code' => 'UNAUTHENTICATED'], 401);
        }

        $this->passwordService->change($user, $data['new_password']);
        $token = $this->authService->createToken($user);

        return response()->json([
            'message' => 'Mot de passe modifié avec succès.',
            'data' => [
                'token' => $token,
                'must_change_password' => false,
            ],
        ]);
    }
}
