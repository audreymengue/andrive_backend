<?php

namespace App\Services;

use App\Http\Resources\ParamLieuResource;
use App\Http\Resources\ParamTypeSortieResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\TypeSortie;
use App\Models\User;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ParametrageSecurity
{
    private ?RateLimiter $reauthenticationRateLimiter = null;

    public function __construct(
        private TypeSortieService $typeSortieService,
        private EntiteGeographiqueService $entiteGeographiqueService,
    ) {}

    /** @return list<string> */
    public static function adminPermissions(): array
    {
        $permissions = ['read_temporary_password', 'read_parametrage_audit'];
        foreach (['user' => ['read', 'create', 'update', 'activate', 'deactivate'], 'role' => ['read', 'create', 'update', 'delete'], 'permission' => ['read', 'create', 'update', 'delete'], 'direction' => ['read', 'create', 'update', 'activate', 'deactivate'], 'service' => ['read', 'create', 'update', 'activate', 'deactivate'], 'type_sortie' => ['read', 'create', 'update', 'delete', 'activate', 'deactivate'], 'entite_geographique' => ['read', 'create', 'update', 'delete', 'activate', 'deactivate'], 'vehicle' => ['read', 'create', 'update', 'activate', 'deactivate'], 'driver' => ['read', 'create', 'update', 'activate', 'deactivate']] as $kind => $actions) {
            foreach ($actions as $action) {
                $permissions[] = $action.'_'.$kind;
            }
        }

        return [...$permissions, 'reset_user_password', 'assign_role', 'assign_permission'];
    }

    /** @return list<string> */
    public static function demandeurPermissions(): array
    {
        return ['read_demande', 'create_demande', 'update_demande', 'submit_demande', 'cancel_demande', 'read_direction', 'read_service', 'read_type_sortie', 'read_entite_geographique'];
    }

    public function lock(): void
    {
        if (DB::table('organisation_guards')->where('id', 1)->lockForUpdate()->first() === null) {
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'La garde de sécurité est indisponible.');
        }
    }

    public function authorize(Request $request, string $permission): User
    {
        $actor = $request->user()?->fresh();
        if ($actor === null) {
            $this->fail(401, 'UNAUTHENTICATED', 'Non authentifié.');
        }
        if ($actor->statut !== 'ACTIF') {
            $this->fail(403, 'FORBIDDEN', 'Compte inactif.');
        }
        if ($actor->must_change_password) {
            $this->fail(403, 'PASSWORD_CHANGE_REQUIRED', 'Le changement du mot de passe est obligatoire.');
        }
        if (! $actor->roles()->where('system_key', 'super_admin')->where('statut', 'ACTIF')->exists() || ! $actor->hasPermissionTo($permission)) {
            $this->fail(403, 'FORBIDDEN', 'Habilitation administrative requise.');
        }
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! $token->newQuery()->whereKey($token->id)->exists()) {
            $this->fail(401, 'UNAUTHENTICATED', 'Session révoquée.');
        }

        return $actor;
    }

    public function handle(Request $request, Closure $next, string $permission, string $kind): Response
    {
        try {
            $this->authorize($request, $permission);
            if ($request->isMethod('GET')) {
                return $next($request);
            }

            return DB::transaction(function () use ($request, $next, $permission, $kind): Response {
                $this->lock();
                $actor = $this->authorize($request, $permission);
                $id = $request->route('id');
                $reveal = str_ends_with($request->path(), '/temporary-password/reveal');
                $delete = $request->isMethod('DELETE');
                $control = $delete
                    ? ['version_attendue' => $request->header('X-Expected-Version'), 'motif' => rawurldecode($request->header('X-Change-Reason', ''))]
                    : $request->only(['version_attendue', 'motif', 'password_generation']);
                $rules = ['version_attendue' => $id === null ? ['sometimes', 'integer', 'min:0'] : ['required', 'integer', 'min:0'], 'motif' => ['sometimes', 'nullable', 'string', 'max:2000']];
                if (! $delete && $id !== null) {
                    $rules['version_attendue'][] = 'numeric:strict';
                }
                if ($reveal) {
                    $rules['password_generation'] = ['required', 'integer', 'numeric:strict', 'min:1'];
                }
                if ($delete || $reveal || in_array($permission, ['activate_user', 'deactivate_user', 'activate_type_sortie', 'deactivate_type_sortie', 'activate_entite_geographique', 'deactivate_entite_geographique', 'reset_user_password', 'assign_role', 'assign_permission'], true) || $request->hasAny(['direction_id', 'service_id', 'statut']) && $id !== null) {
                    $rules['motif'] = ['required', 'string', 'max:2000'];
                }
                $data = Validator::make($control, $rules)->validate();
                if ($delete && $request->getContent() !== '') {
                    throw ValidationException::withMessages(['body' => ['Le DELETE utilise uniquement les en-têtes de contrôle.']]);
                }
                $key = $request->header('Idempotency-Key');
                if (! $reveal) {
                    Validator::make(['Idempotency-Key' => $key], ['Idempotency-Key' => ['required', 'uuid']])->validate();
                }
                if (in_array($kind, ['user', 'role', 'permission', 'type_sortie', 'entite_geographique'], true)) {
                    $this->rejectUnknownReferenceFields($request, $kind, $permission);
                }
                if (in_array($permission, ['assign_role', 'assign_permission'], true)) {
                    $field = $permission === 'assign_role' ? 'role_ids' : 'permission_ids';
                    Validator::make($request->only($field), [$field => ['present', 'array', 'list'], $field.'.*' => ['integer', 'numeric:strict', 'min:1', 'distinct']])->validate();
                }
                if ($permission === 'reset_user_password' || $reveal || $permission === 'assign_role' && $this->addsSuperAdmin($request, (int) $id)) {
                    $this->reauthenticate($request, $actor, $reveal ? 'current_password' : 'actor_password');
                }
                $intent = $request->only(['nom', 'prenom', 'email', 'direction_id', 'service_id', 'statut', 'role_ids', 'permission_ids', 'code', 'libelle', 'adresse', 'latitude', 'longitude', 'description', 'version_attendue', 'motif', 'password_generation']);
                if ($delete) {
                    $intent = $data;
                }
                $canonical = function (mixed $value) use (&$canonical): mixed {
                    if (! is_array($value)) {
                        return $value;
                    }
                    if (! array_is_list($value)) {
                        ksort($value);
                    }

                    return array_map($canonical, $value);
                };
                foreach (['role_ids', 'permission_ids'] as $field) {
                    if (isset($intent[$field]) && is_array($intent[$field])) {
                        sort($intent[$field], SORT_NUMERIC);
                    }
                }
                $fingerprint = hash('sha256', json_encode([$request->method(), $request->path(), $canonical($intent)], JSON_THROW_ON_ERROR));
                if (! $reveal) {
                    $prior = DB::table('organisation_commands')->where('actor_id', $actor->id)->where('command_key', $key)->first();
                    if ($prior !== null) {
                        if (! hash_equals($prior->fingerprint, $fingerprint)) {
                            $this->fail(409, 'IDEMPOTENCY_CONFLICT', 'La clé correspond à une autre intention.');
                        }

                        return $prior->http_status === 204 ? response()->noContent() : response()->json(json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR), $prior->http_status);
                    }
                }
                $class = match ($kind) {
                    'user' => User::class, 'role' => Role::class, 'permission' => Permission::class, 'type_sortie' => TypeSortie::class, 'entite_geographique' => EntiteGeographique::class
                };
                $target = $id === null ? null : $class::query()->lockForUpdate()->findOrFail($id);
                if ($target !== null && (int) $target->lock_version !== (int) $data['version_attendue']) {
                    $this->fail(409, 'VERSION_CONFLICT', 'La version a changé.');
                }
                if ($reveal && (int) $target->password_generation !== (int) $data['password_generation']) {
                    $this->fail(409, 'PASSWORD_GENERATION_CONFLICT', 'La génération du temporaire a changé.');
                }
                $before = $target === null ? null : $this->snapshot($target);
                $organisationBefore = $target instanceof User ? $this->organisation($target) : null;
                $this->protect($request, $target, $permission, $kind);
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }
                if ($reveal) {
                    $this->audit($actor->id, 'reveal', $kind, (int) $id, $data['motif'], $before, $before, null);

                    return $response;
                }
                if ($target === null && $response instanceof JsonResponse) {
                    $body = $response->getData(true);
                    $target = $class::query()->findOrFail($body['data']['id'] ?? $body['data']['user']['id']);
                } else {
                    $target = $target?->fresh();
                }
                if ($target instanceof User && $id === null) {
                    $role = Role::query()->where('system_key', 'demandeur')->where('statut', 'ACTIF')->first();
                    if ($role === null) {
                        $this->fail(409, 'SYSTEM_OBJECT_PROTECTED', 'Le rôle système demandeur doit être synchronisé.');
                    }
                    $target->assignRole($role);
                }
                if ($target !== null) {
                    $after = $this->snapshot($target);
                    if ($before !== null && $after !== $before) {
                        $target->forceFill(['lock_version' => $target->lock_version + 1])->save();
                    }
                    if ($target instanceof User) {
                        $organisationAfter = $this->organisation($target);
                        if ($organisationBefore !== $organisationAfter) {
                            DB::table('user_organisation_histories')->insert(['user_id' => $target->id, 'actor_id' => $actor->id, 'origine' => 'administration', 'motif' => $data['motif'] ?? null, 'before_values' => $organisationBefore === null ? null : json_encode($organisationBefore, JSON_THROW_ON_ERROR), 'after_values' => json_encode($organisationAfter, JSON_THROW_ON_ERROR), 'command_key' => $key, 'created_at' => now()]);
                        }
                    }
                    $after = $this->snapshot($target);
                    $body = $response->getData(true);
                    if ($permission === 'reset_user_password' && $target instanceof User) {
                        $body['data'] = [
                            'must_change_password' => (bool) $target->must_change_password,
                            'temporary_password_available' => $target->must_change_password && $target->temporary_password !== null,
                            'password_generation' => (int) $target->password_generation,
                            'lock_version' => (int) $target->lock_version,
                        ];
                    } else {
                        $resource = match ($kind) {
                            'user' => new UserResource($target->load('roles.permissions')),
                            'role' => new RoleResource($target->load('permissions')),
                            'permission' => new PermissionResource($target),
                            'type_sortie' => new ParamTypeSortieResource($target),
                            'entite_geographique' => new ParamLieuResource($target),
                        };
                        $body['data'] = $resource->resolve($request);
                    }
                    $response->setData($body);
                } else {
                    $after = null;
                }
                $this->assertAdministratorRemains();
                if ($before !== $after) {
                    $this->audit($actor->id, $permission, $kind, (int) ($target?->id ?? $id), $data['motif'] ?? null, $before, $after, $key);
                }
                DB::table('organisation_commands')->insert(['actor_id' => $actor->id, 'command_key' => $key, 'fingerprint' => $fingerprint, 'http_status' => $response->getStatusCode(), 'response' => json_encode($response instanceof JsonResponse ? $response->getData(true) : null, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                return $response;
            }, 3);
        } catch (HttpResponseException|ValidationException|ModelNotFoundException $exception) {
            if ($request->user() !== null && ! $request->isMethod('GET')) {
                $this->audit($request->user()->id, 'refus', $kind, (int) $request->route('id', 0), null, null, ['operation' => $permission], null);
            }
            throw $exception;
        } catch (QueryException $exception) {
            report(new \RuntimeException('Échec de stockage Paramétrage ; détails SQL et secrets exclus du journal.'));
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'Opération non validée ; réessayez avec la même clé.');
        }
    }

    private function addsSuperAdmin(Request $request, int $id): bool
    {
        $super = Role::query()->where('system_key', 'super_admin')->first();

        return $super !== null && in_array($super->id, $request->input('role_ids', []), true) && ! User::query()->findOrFail($id)->roles()->whereKey($super->id)->exists();
    }

    private function reauthenticate(Request $request, User $actor, string $passwordField = 'current_password'): void
    {
        $key = 'parametrage-reauth:'.$actor->id.':'.$request->ip();
        $limiter = $this->reauthenticationLimiter();
        if ($limiter->tooManyAttempts($key, 5)) {
            $this->fail(429, 'TOO_MANY_ATTEMPTS', 'Trop de tentatives de réauthentification.', [
                'Retry-After' => (string) max(1, $limiter->availableIn($key)),
            ]);
        }
        $limiter->hit($key, 60);
        $rules = $passwordField === 'actor_password'
            ? ['required', 'string', 'min:8', 'max:12']
            : ['required', 'string'];
        Validator::make($request->only($passwordField), [$passwordField => $rules])->validate();
        if (! Hash::check($request->input($passwordField), $actor->password)) {
            $this->fail(401, 'REAUTHENTICATION_FAILED', 'Réauthentification incorrecte.');
        }
    }

    /**
     * Les echecs d'authentification doivent survivre au rollback metier.
     */
    private function reauthenticationLimiter(): RateLimiter
    {
        if ($this->reauthenticationRateLimiter !== null) {
            return $this->reauthenticationRateLimiter;
        }
        $cache = app(CacheManager::class)->store(config('cache.limiter'));
        if ($cache->getStore() instanceof DatabaseStore) {
            $store = clone $cache->getStore();
            $connection = $store->getConnection()->getConfig();
            $connection['name'] = 'parametrage_reauthentication_limiter';
            $store->setConnection(DB::build($connection));
            $cache = new Repository($store);
        }

        return $this->reauthenticationRateLimiter = new RateLimiter($cache);
    }

    private function protect(Request $request, ?Model $target, string $permission, string $kind): void
    {
        $this->rejectUnknownReferenceFields($request, $kind, $permission);

        if ($target instanceof TypeSortie && $permission === 'update_type_sortie' && $this->typeSortieService->isUsed($target)) {
            $this->fail(409, 'REFERENCE_IN_USE', 'Ce type de sortie est utilisé et ne peut plus être modifié.');
        }
        if ($target instanceof EntiteGeographique && $permission === 'update_entite_geographique' && $this->entiteGeographiqueService->isUsed($target)) {
            $this->fail(409, 'REFERENCE_IN_USE', 'Cette entité géographique est utilisée et ne peut plus être modifiée.');
        }
        foreach (['assign_role' => ['role_ids', Role::class], 'assign_permission' => ['permission_ids', Permission::class]] as $operation => [$field, $class]) {
            if ($permission === $operation) {
                $ids = Validator::make($request->only($field), [$field => ['present', 'array'], $field.'.*' => ['integer', 'distinct']])->validate()[$field];
                if ($class::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    throw (new ModelNotFoundException)->setModel($class, $ids);
                }
            }
        }
        if ($target instanceof Role && $target->system_key !== null) {
            if ($permission === 'delete_role' || $request->has('nom') && $request->input('nom') !== $target->name || $request->has('statut') && $request->input('statut') !== 'ACTIF') {
                $this->fail(409, 'SYSTEM_OBJECT_PROTECTED', 'Identité et statut du rôle système protégés.');
            }
            if ($permission === 'assign_permission') {
                $socle = $target->system_key === 'super_admin' ? self::adminPermissions() : self::demandeurPermissions();
                $names = Permission::query()->whereIn('id', $request->input('permission_ids', []))->pluck('name')->all();
                if (array_diff($socle, $names) !== []) {
                    $this->fail(409, 'SYSTEM_OBJECT_PROTECTED', 'Le socle du rôle système est protégé.');
                }
            }
        }
        if ($target instanceof Role && $permission === 'delete_role' && $this->historicallyReferenced('user', 'role_ids', $target->id)) {
            $this->fail(409, 'REFERENCE_IN_USE', 'Ce rôle est référencé dans un historique d’attribution.');
        }
        if ($target instanceof Permission && in_array($permission, ['delete_permission', 'update_permission'], true)) {
            $protected = in_array($target->name, [...self::adminPermissions(), ...self::demandeurPermissions()], true);
            $rename = $request->has('code') && $request->input('code') !== $target->name;
            if (($permission === 'delete_permission' || $rename) && $protected) {
                $this->fail(409, 'SYSTEM_OBJECT_PROTECTED', 'Code de permission système protégé.');
            }
            if (($permission === 'delete_permission' || $rename) && ($target->roles()->exists() || $target->users()->exists() || $this->historicallyReferenced('role', 'permission_ids', $target->id))) {
                $this->fail(409, 'REFERENCE_IN_USE', 'Cette permission est encore utilisée ou historiquement attribuée.');
            }
        }
        if ($target instanceof User && $permission === 'update_user' || $permission === 'create_user') {
            $directionId = $request->input('direction_id', $target?->direction_id);
            $serviceId = $request->input('service_id', $target?->service_id);
            $serviceId = $serviceId === null ? null : (int) $serviceId;
            if ($directionId === null && ($target === null || $request->has('direction_id'))) {
                throw ValidationException::withMessages(['direction_id' => ['La direction est obligatoire.']]);
            }
            if ($target !== null && (int) $directionId !== (int) $target->direction_id && $target->service_id !== null && ! $request->exists('service_id')) {
                throw ValidationException::withMessages(['service_id' => ['Précisez le nouveau service ou null.']]);
            }
            $changed = $target === null || (int) $directionId !== (int) $target->direction_id || $serviceId !== $target->service_id;
            if ($changed) {
                $direction = Direction::query()->findOrFail($directionId);
                $service = $serviceId === null ? null : Service::query()->findOrFail($serviceId);
                if ($direction->statut !== 'ACTIF' || $service !== null && ($service->statut !== 'ACTIF' || (int) $service->direction_id !== (int) $directionId)) {
                    throw ValidationException::withMessages(['service_id' => ['Le rattachement exige une direction active et un service actif de cette direction.']]);
                }
            }
        }
    }

    private function rejectUnknownReferenceFields(Request $request, string $kind, string $permission): void
    {
        $allowedFields = match ($kind) {
            'user' => match ($permission) {
                'activate_user', 'deactivate_user' => ['version_attendue', 'motif'],
                'reset_user_password' => ['version_attendue', 'motif', 'actor_password'],
                'read_temporary_password' => ['version_attendue', 'password_generation', 'motif', 'current_password'],
                'assign_role' => ['role_ids', 'version_attendue', 'motif', 'actor_password'],
                default => null,
            },
            'role' => match ($permission) {
                'create_role' => ['nom', 'description', 'statut'],
                'update_role' => ['nom', 'description', 'statut', 'version_attendue', 'motif'],
                'delete_role' => [],
                'assign_permission' => ['permission_ids', 'version_attendue', 'motif'],
                default => null,
            },
            'permission' => match ($permission) {
                'create_permission' => ['code', 'libelle', 'description'],
                'update_permission' => ['code', 'libelle', 'description', 'version_attendue', 'motif'],
                'delete_permission' => [],
                default => null,
            },
            'type_sortie' => match ($permission) {
                'create_type_sortie' => ['code', 'libelle'],
                'update_type_sortie' => ['libelle', 'version_attendue', 'motif'],
                'delete_type_sortie' => [],
                'activate_type_sortie', 'deactivate_type_sortie' => ['version_attendue', 'motif'],
                default => null,
            },
            'entite_geographique' => match ($permission) {
                'create_entite_geographique' => ['adresse', 'latitude', 'longitude', 'description'],
                'update_entite_geographique' => ['adresse', 'latitude', 'longitude', 'description', 'version_attendue', 'motif'],
                'delete_entite_geographique' => [],
                'activate_entite_geographique', 'deactivate_entite_geographique' => ['version_attendue', 'motif'],
                default => null,
            },
            default => null,
        };
        if ($allowedFields === null) {
            return;
        }

        $unknownFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($unknownFields !== []) {
            $errors = [];
            foreach ($unknownFields as $field) {
                $errors[$field] = ['Ce champ n’est pas pris en charge par cette opération.'];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function historicallyReferenced(string $kind, string $field, int $id): bool
    {
        return DB::table('organisation_audits')->where('object_type', $kind)->where(function ($query) use ($field, $id): void {
            $query->whereJsonContains('before_values->'.$field, $id)->orWhereJsonContains('after_values->'.$field, $id);
        })->exists();
    }

    public function assertAdministratorRemains(): void
    {
        $role = Role::query()->where('system_key', 'super_admin')->where('statut', 'ACTIF')->first();
        if ($role === null || array_diff(self::adminPermissions(), $role->permissions()->pluck('name')->all()) !== [] || ! $role->users()->where('statut', 'ACTIF')->exists()) {
            $this->fail(409, 'LAST_EFFECTIVE_ADMIN', 'Au moins un administrateur effectif doit être conservé.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $target): array
    {
        $data = match (true) {
            $target instanceof User => $target->only(['id', 'nom', 'prenom', 'email', 'statut', 'direction_id', 'service_id', 'must_change_password', 'password_generation', 'lock_version']),
            $target instanceof Role => $target->only(['id', 'name', 'description', 'statut', 'system_key', 'lock_version']),
            $target instanceof TypeSortie => $target->only(['id', 'code', 'libelle', 'statut', 'lock_version']),
            $target instanceof EntiteGeographique => $target->only(['id', 'adresse', 'latitude', 'longitude', 'description', 'statut', 'lock_version']),
            default => $target->only(['id', 'name', 'libelle', 'description', 'lock_version']),
        };
        if ($target instanceof User) {
            $data['role_ids'] = $target->roles()->orderBy('id')->pluck('roles.id')->all();
        }
        if ($target instanceof Role) {
            $data['permission_ids'] = $target->permissions()->orderBy('id')->pluck('permissions.id')->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function organisation(User $user): array
    {
        return ['direction_id' => $user->direction_id, 'service_id' => $user->service_id, 'direction' => $user->direction()->first()?->only(['id', 'nom', 'code']), 'service' => $user->service()->first()?->only(['id', 'nom', 'code'])];
    }

    private function audit(int $actor, string $action, string $kind, int $id, ?string $motif, ?array $before, ?array $after, ?string $key): void
    {
        DB::table('organisation_audits')->insert(['actor_id' => $actor, 'action' => $action, 'object_type' => $kind, 'object_id' => $id, 'motif' => $motif, 'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_values' => json_encode($after, JSON_THROW_ON_ERROR), 'command_key' => $key ?? (string) Str::uuid(), 'created_at' => now()]);
    }

    /** @param array<string, string> $headers */
    public function fail(int $status, string $code, string $message, array $headers = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status, $headers));
    }
}
