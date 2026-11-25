<?php

namespace App\Services;

use App\Models\PushDevice;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class PushDeviceService
{
    public function __construct(private ParametrageSecurity $security) {}

    public function register(User $user, string $installationId, string $platform, string $token): void
    {
        $this->withSession($user, function (PersonalAccessToken $session) use ($user, $installationId, $platform, $token): void {
            $tokenHash = hash('sha256', $token);
            $device = PushDevice::query()->where('installation_id', $installationId)->lockForUpdate()->first();
            $tokenClaimedElsewhere = PushDevice::query()->where('token_hash', $tokenHash)
                ->where('installation_id', '<>', $installationId)->exists();

            if ($tokenClaimedElsewhere || ($device !== null && $device->user_id !== $user->id && ! hash_equals($device->token_hash, $tokenHash))) {
                $this->fail(409, 'PUSH_DEVICE_CONFLICT', 'Cette inscription est incompatible avec un appareil déjà enregistré.');
            }

            $device ??= new PushDevice;
            $device->installation_id = $installationId;
            $device->user_id = $user->id;
            $device->personal_access_token_id = $session->id;
            $device->plateforme = $platform;
            if ($device->token_hash !== $tokenHash) {
                $device->token = $token;
                $device->token_hash = $tokenHash;
            }
            if ($device->isDirty()) {
                $device->save();
            }
        });
    }

    public function remove(User $user, string $installationId): void
    {
        $this->withSession($user, function () use ($user, $installationId): void {
            PushDevice::query()->where('user_id', $user->id)->where('installation_id', $installationId)->delete();
        });
    }

    /** @param Closure(PersonalAccessToken): void $action */
    private function withSession(User $user, Closure $action): void
    {
        try {
            DB::transaction(function () use ($user, $action): void {
                $this->security->lock();
                $session = $user->currentAccessToken();
                if (! $session instanceof PersonalAccessToken || ! $user->tokens()->whereKey($session->id)->lockForUpdate()->first(['id'])) {
                    $this->fail(401, 'UNAUTHENTICATED', 'Session révoquée.');
                }
                $actor = $user->fresh();
                if ($actor === null || $actor->statut !== 'ACTIF') {
                    $this->fail(403, 'FORBIDDEN', 'Compte inactif.');
                }
                if ($actor->must_change_password) {
                    $this->fail(403, 'PASSWORD_CHANGE_REQUIRED', 'Vous devez modifier votre mot de passe avant de poursuivre.');
                }

                $action($session);
            }, 3);
        } catch (QueryException $exception) {
            report(new \RuntimeException('Stockage des appareils push indisponible (SQLSTATE '.$exception->getCode().').'));
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'Opération temporairement indisponible.');
        }
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
