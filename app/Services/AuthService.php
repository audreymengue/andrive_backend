<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * @return array{user: User|null, reason: 'inactive'|'invalid_credentials'|null}
     */
    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return ['user' => null, 'reason' => 'invalid_credentials'];
        }

        if ($user->statut !== 'ACTIF') {
            return ['user' => null, 'reason' => 'inactive'];
        }

        return ['user' => $user, 'reason' => null];
    }

    public function createToken(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            app(ParametrageSecurity::class)->lock();
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($current->statut !== 'ACTIF') {
                abort(403, 'Compte inactif.');
            }
            if ($current->password_generation !== $user->password_generation || ! hash_equals($current->password, $user->password)) {
                abort(401, 'Identifiants remplacés ; reconnectez-vous.');
            }

            return $current->createToken('auth-token')->plainTextToken;
        }, 3);
    }

    public function logout(User $user): void
    {
        DB::transaction(function () use ($user): void {
            app(ParametrageSecurity::class)->lock();
            $user->currentAccessToken()->delete();
        }, 3);
    }
}
