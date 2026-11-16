<?php

namespace App\Services;

use App\Http\Requests\ResourceProfileRequest;
use App\Http\Resources\ChauffeurResource;
use App\Http\Resources\VehiculeResource;
use App\Models\Chauffeur;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ResourceProfileService
{
    public function list(ResourceProfileRequest $request): LengthAwarePaginator
    {
        $kind = $request->route('resource_profile_kind');
        $query = $this->modelClass($kind)::query();
        $data = $request->validated();

        if (isset($data['statut'])) {
            $query->where('statut', $data['statut']);
        }
        if (isset($data['search'])) {
            $search = $data['search'];
            if ($kind === 'vehicule') {
                $query->where('immatriculation', 'like', '%'.$search.'%');
            } else {
                $query->whereHas('user', fn ($userQuery) => $userQuery->where(fn ($nameQuery) => $nameQuery->where('nom', 'like', '%'.$search.'%')->orWhere('prenom', 'like', '%'.$search.'%')));
            }
        }

        if ($kind === 'chauffeur') {
            $query->with('user:id,nom,prenom,statut');
        }

        return $query->orderBy('id')->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);
    }

    public function find(ResourceProfileRequest $request, int $id): Model
    {
        $query = $this->modelClass($request->route('resource_profile_kind'))::query();
        if ($request->route('resource_profile_kind') === 'chauffeur') {
            $query->with('user:id,nom,prenom,statut');
        }
        $model = $query->find($id);
        if ($model === null) {
            $this->fail(404, 'RESOURCE_NOT_FOUND', 'La ressource demandée n’existe pas.');
        }

        return $model;
    }

    public function mutate(ResourceProfileRequest $request, ?int $id): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $id): JsonResponse {
                if (DB::table('organisation_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'La garde organisationnelle est indisponible.');
                }

                $data = $request->validated();
                $key = $data['idempotency_key'];
                unset($data['idempotency_key']);
                $kind = $request->route('resource_profile_kind');
                $action = $request->route('resource_profile_action');
                $permission = $request->route('resource_profile_permission');
                $actor = $this->authorizeCurrentActor($request, $permission);
                ksort($data);
                $fingerprint = hash('sha256', json_encode([$kind, $action, $id, $data], JSON_THROW_ON_ERROR));
                $prior = DB::table('organisation_commands')->where('actor_id', $actor->id)->where('command_key', $key)->first();

                if ($prior !== null) {
                    if (! hash_equals($prior->fingerprint, $fingerprint)) {
                        $this->fail(409, 'IDEMPOTENCY_CONFLICT', 'Cette clé a déjà été utilisée pour une autre intention.');
                    }

                    return response()->json(json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR), $prior->http_status);
                }

                $class = $this->modelClass($kind);
                $model = $id === null ? new $class : $class::query()->lockForUpdate()->find($id);
                if ($id !== null && $model === null) {
                    $this->fail(404, 'RESOURCE_NOT_FOUND', 'La ressource demandée n’existe pas.');
                }
                if ($id !== null && (int) $model->lock_version !== (int) $data['version_attendue']) {
                    $this->fail(409, 'VERSION_CONFLICT', 'La version a changé. Rechargez la ressource avant de réessayer.');
                }

                $before = $id === null ? null : $this->snapshot($model);
                if ($action === 'create') {
                    $this->createProfile($model, $kind, $data);
                } elseif ($action === 'update') {
                    $this->updateProfile($model, $kind, $data);
                } else {
                    $model->statut = $action === 'activate' ? 'ACTIF' : 'INACTIF';
                }

                $changed = $id === null || $model->isDirty();
                if ($changed) {
                    $model->lock_version = $id === null ? 0 : $model->lock_version + 1;
                    $model->save();
                }

                if ($model instanceof Chauffeur) {
                    $model->load('user:id,nom,prenom,statut');
                }

                $after = $this->snapshot($model);
                $responseData = $this->resourceData($model, $request);
                if ($changed) {
                    DB::table('organisation_audits')->insert([
                        'actor_id' => $actor->id,
                        'action' => $action,
                        'object_type' => $kind,
                        'object_id' => $model->id,
                        'motif' => $data['motif'] ?? null,
                        'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                        'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
                        'command_key' => $key,
                        'created_at' => now(),
                    ]);
                }

                $body = [
                    'message' => $this->successMessage($kind, $action),
                    'data' => $responseData,
                ];
                $status = $id === null ? 201 : 200;
                DB::table('organisation_commands')->insert([
                    'actor_id' => $actor->id,
                    'command_key' => $key,
                    'fingerprint' => $fingerprint,
                    'http_status' => $status,
                    'response' => json_encode($body, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return response()->json($body, $status);
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[1] ?? $exception->getCode()), ['1062', '19', '23505'], true)) {
                $this->fail(409, 'RESOURCE_CONFLICT', 'Une immatriculation ou un compte chauffeur est déjà associé à une autre ressource.');
            }

            report($exception);
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'Opération temporairement indisponible ; aucun changement partiel validé.');
        }
    }

    private function createProfile(Model $model, string $kind, array $data): void
    {
        if ($kind === 'vehicule') {
            $model->fill([
                'immatriculation' => $data['immatriculation'],
                'places_utiles' => $data['places_utiles'],
                'statut' => 'ACTIF',
            ]);

            return;
        }

        $userId = $data['user_id'] ?? null;
        if ($userId !== null) {
            $this->assertUserExists((int) $userId);
            $this->assertUserNotLinked((int) $userId);
        }
        $model->fill(['user_id' => $userId, 'statut' => 'ACTIF']);
    }

    private function updateProfile(Model $model, string $kind, array $data): void
    {
        if ($kind === 'vehicule') {
            $model->fill(array_intersect_key($data, array_flip(['immatriculation', 'places_utiles'])));

            return;
        }

        if (array_key_exists('user_id', $data)) {
            if ($data['user_id'] !== null) {
                $this->assertUserExists((int) $data['user_id']);
                $this->assertUserNotLinked((int) $data['user_id'], (int) $model->id);
            }
            $model->user_id = $data['user_id'];
        }
    }

    private function assertUserExists(int $userId): void
    {
        if (User::query()->lockForUpdate()->whereKey($userId)->first() !== null) {
            return;
        }

        $this->fail(404, 'USER_NOT_FOUND', 'Le compte utilisateur sélectionné n’existe pas.');
    }

    private function assertUserNotLinked(int $userId, ?int $exceptChauffeurId = null): void
    {
        $query = Chauffeur::query()->where('user_id', $userId);
        if ($exceptChauffeurId !== null) {
            $query->where('id', '<>', $exceptChauffeurId);
        }
        if (! $query->exists()) {
            return;
        }

        $this->fail(409, 'RESOURCE_CONFLICT', 'Ce compte est déjà lié à un profil chauffeur.');
    }

    private function authorizeCurrentActor(ResourceProfileRequest $request, string $permission): User
    {
        $actor = $request->user()?->fresh();
        if ($actor === null || $actor->statut !== 'ACTIF') {
            $this->fail(403, 'FORBIDDEN', 'Compte inactif ou habilitation manquante.');
        }
        if ($actor->must_change_password) {
            $this->fail(403, 'PASSWORD_CHANGE_REQUIRED', 'Vous devez modifier votre mot de passe avant de poursuivre.');
        }
        if (! $actor->hasPermissionTo($permission)) {
            $this->fail(403, 'FORBIDDEN', 'Vous n’avez pas la permission d’effectuer cette action.');
        }

        return $actor;
    }

    private function resourceData(Model $model, ResourceProfileRequest $request): array
    {
        return $model instanceof Vehicule
            ? (new VehiculeResource($model))->resolve($request)
            : (new ChauffeurResource($model))->resolve($request);
    }

    /** @return array<string, int|string|null> */
    private function snapshot(Model $model): array
    {
        if ($model instanceof Vehicule) {
            return [
                'id' => (int) $model->id,
                'immatriculation' => $model->immatriculation,
                'places_utiles' => (int) $model->places_utiles,
                'statut' => $model->statut,
                'lock_version' => (int) $model->lock_version,
            ];
        }

        return [
            'id' => (int) $model->id,
            'user_id' => $model->user_id === null ? null : (int) $model->user_id,
            'statut' => $model->statut,
            'lock_version' => (int) $model->lock_version,
        ];
    }

    private function successMessage(string $kind, string $action): string
    {
        $label = $kind === 'vehicule' ? 'Véhicule' : 'Profil chauffeur';
        $verb = match ($action) {
            'create' => 'créé',
            'update' => 'modifié',
            'activate' => 'activé',
            'deactivate' => 'désactivé',
            default => 'récupéré',
        };

        return $label.' '.$verb.' avec succès.';
    }

    /** @return class-string<Vehicule>|class-string<Chauffeur> */
    private function modelClass(string $kind): string
    {
        return match ($kind) {
            'vehicule' => Vehicule::class,
            'chauffeur' => Chauffeur::class,
            default => throw new \InvalidArgumentException('Type de profil inconnu.'),
        };
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
