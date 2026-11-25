<?php

namespace App\Services;

use App\Http\Requests\OrganisationRequest;
use App\Http\Resources\OrganisationResource;
use App\Models\Direction;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrganisationService
{
    public function list(OrganisationRequest $request): LengthAwarePaginator
    {
        $class = $this->modelClass($request);
        $query = $class::query();
        if (! $request->route('organisation_admin')) {
            $query->where('statut', 'ACTIF');
            if ($class === Direction::class) {
                $query->with('rattachements:id,direction_id,code,nom');
            }
        } elseif ($request->filled('statut')) {
            $query->where('statut', $request->validated('statut'));
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn ($query) => $query->where('nom', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }
        if ($class === Service::class && $request->filled('direction_id')) {
            $query->where('direction_id', $request->integer('direction_id'));
        }

        return $query->orderBy('id')->paginate($request->integer('per_page', 20));
    }

    public function find(OrganisationRequest $request, int $id): Model
    {
        return $this->modelClass($request)::query()->findOrFail($id);
    }

    public function mutate(OrganisationRequest $request, ?int $id): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $id): JsonResponse {
                $db = DB::class;
                if ($db::table('organisation_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'La garde organisationnelle est indisponible.');
                }
                if (! $request->authorize()) {
                    $this->fail(403, 'FORBIDDEN', 'Les habilitations ont changé.');
                }
                $data = $request->validated();
                $key = $data['idempotency_key'];
                unset($data['idempotency_key']);
                ksort($data);
                $action = $request->route('organisation_action');
                $kind = $request->route('organisation_kind');
                $fingerprint = hash('sha256', json_encode([$kind, $action, $id, $data], JSON_THROW_ON_ERROR));
                $prior = $db::table('organisation_commands')->where('actor_id', $request->user()->id)->where('command_key', $key)->first();
                if ($prior !== null) {
                    if (! hash_equals($prior->fingerprint, $fingerprint)) {
                        $this->fail(409, 'IDEMPOTENCY_CONFLICT', 'Cette clé a déjà été utilisée pour une autre intention.');
                    }

                    return response()->json(json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR), $prior->http_status);
                }
                $class = $this->modelClass($request);
                $model = $id === null ? new $class : $class::query()->lockForUpdate()->findOrFail($id);
                if ($id !== null && $model->lock_version !== (int) $data['version_attendue']) {
                    $this->fail(409, 'VERSION_CONFLICT', 'La version a changé. Rechargez les données.');
                }
                $before = $id === null ? null : (new OrganisationResource($model))->resolve($request);
                if ($action === 'create') {
                    if ($kind === 'service') {
                        $parent = Direction::query()->lockForUpdate()->findOrFail($data['direction_id']);
                        if ($parent->statut !== 'ACTIF') {
                            $this->invalid('direction_id', 'Une nouvelle affectation exige une direction active.');
                        }
                    }
                    $duplicate = $class::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])]);
                    if ($kind === 'service') {
                        $duplicate->where('direction_id', $data['direction_id']);
                    }
                    if ($duplicate->exists()) {
                        $this->invalid('code', 'Ce code existe déjà dans ce périmètre.');
                    }
                    $model->fill(array_intersect_key($data, array_flip(['nom', 'code', 'description', 'direction_id'])));
                    $model->statut = 'ACTIF';
                } elseif ($action === 'update') {
                    $model->fill(array_intersect_key($data, array_flip(['nom', 'description'])));
                } else {
                    $model->statut = $action === 'activate' ? 'ACTIF' : 'INACTIF';
                }
                $changed = $id === null || $model->isDirty();
                if ($changed) {
                    $model->lock_version = $id === null ? 0 : $model->lock_version + 1;
                    $model->save();
                }
                $after = (new OrganisationResource($model))->resolve($request);
                if ($changed) {
                    $db::table('organisation_audits')->insert(['actor_id' => $request->user()->id, 'action' => $action, 'object_type' => $kind, 'object_id' => $model->id, 'motif' => $data['motif'] ?? null, 'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_values' => json_encode($after, JSON_THROW_ON_ERROR), 'command_key' => $key, 'created_at' => now()]);
                }
                $body = ['message' => 'Opération effectuée avec succès.', 'data' => $after];
                $status = $id === null ? 201 : 200;
                $db::table('organisation_commands')->insert(['actor_id' => $request->user()->id, 'command_key' => $key, 'fingerprint' => $fingerprint, 'http_status' => $status, 'response' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return response()->json($body, $status);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $this->invalid('code', 'Ce code existe déjà dans ce périmètre.');
            }
            report($exception);
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'Opération temporairement indisponible ; aucun changement partiel validé.');
        }
    }

    /** @return class-string<Direction>|class-string<Service> */
    private function modelClass(OrganisationRequest $request): string
    {
        return $request->route('organisation_kind') === 'service' ? Service::class : Direction::class;
    }

    private function invalid(string $field, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => [$field => [$message]]], 422));
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
