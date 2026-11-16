<?php

namespace App\Services;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\IndexResourceAvailabilityRequest;
use App\Http\Requests\IndexTransportSignalementRequest;
use App\Http\Requests\ReturnResourceAvailabilityRequest;
use App\Http\Requests\StoreResourceAvailabilityRequest;
use App\Http\Requests\StoreTransportSignalementRequest;
use App\Http\Requests\UpdateResourceAvailabilityRequest;
use App\Http\Resources\ResourceAvailabilityResource;
use App\Http\Resources\TransportSignalementResource;
use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\CoursePreparation;
use App\Models\IndisponibiliteRessource;
use App\Models\SignalementTransport;
use App\Models\TransportResourceEvent;
use App\Models\User;
use App\Models\Vehicule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TransportService
{
    public function listSignalements(IndexTransportSignalementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = SignalementTransport::query()->orderByDesc('id');

        if (isset($data['course_id'])) {
            $query->where('course_id', $data['course_id']);
        }
        if (isset($data['type_ressource'])) {
            $data['type_ressource'] === 'VEHICULE'
                ? $query->whereNotNull('vehicule_id')
                : $query->whereNull('vehicule_id');
        }
        if (isset($data['ressource_id'])) {
            $resourceType = $data['type_ressource'] ?? null;
            $query->where(function (Builder $resourceQuery) use ($data, $resourceType): void {
                if ($resourceType === 'VEHICULE') {
                    $resourceQuery->where('vehicule_id', $data['ressource_id']);
                } elseif ($resourceType === 'CHAUFFEUR') {
                    $resourceQuery->where('chauffeur_id', $data['ressource_id'])->whereNull('vehicule_id');
                } else {
                    $resourceQuery->where('vehicule_id', $data['ressource_id'])
                        ->orWhere(function (Builder $driverQuery) use ($data): void {
                            $driverQuery->whereNull('vehicule_id')->where('chauffeur_id', $data['ressource_id']);
                        });
                }
            });
        }

        $page = $query->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'message' => 'Signalements récupérés.',
            'data' => TransportSignalementResource::collection($page->getCollection())->resolve($request),
            'meta' => $this->meta($page),
        ]);
    }

    public function showSignalement(Request $request, int $id): JsonResponse
    {
        $signalement = SignalementTransport::query()->find($id);
        if ($signalement === null) {
            $this->fail(404, 'NOT_FOUND', 'Le signalement demandé n’existe pas.');
        }

        return response()->json(['message' => 'Signalement récupéré.', 'data' => (new TransportSignalementResource($signalement))->resolve($request)]);
    }

    public function createSignalement(StoreTransportSignalementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $fingerprintData = [
            'type' => $data['type'],
            'type_ressource' => $data['type_ressource'],
            'ressource_id' => (int) $data['ressource_id'],
            'course_id' => isset($data['course_id']) ? (int) $data['course_id'] : null,
            'details' => $data['details'],
        ];

        return $this->executeCommand($request, 'create_signalement', null, $fingerprintData, function (string $key) use ($request, $data): array {
            $reporter = User::query()->lockForUpdate()->find($request->user()->id);
            $chauffeur = $reporter?->chauffeurProfile()->lockForUpdate()->first();
            if ($reporter === null || $reporter->statut !== 'ACTIF' || $chauffeur === null || $chauffeur->statut !== 'ACTIF') {
                $this->fail(403, 'FORBIDDEN', 'Un compte Chauffeur actif est requis pour transmettre un signalement.');
            }

            $vehicle = null;
            $course = null;
            if ($data['type_ressource'] === 'CHAUFFEUR') {
                if ((int) $chauffeur->id !== (int) $data['ressource_id']) {
                    $this->fail(403, 'FORBIDDEN', 'Le chauffeur ne peut signaler que son propre profil.');
                }
            } else {
                $vehicle = Vehicule::query()->lockForUpdate()->find((int) $data['ressource_id']);
                if ($vehicle === null) {
                    $this->fail(404, 'NOT_FOUND', 'Le véhicule signalé n’existe pas.');
                }
            }

            if (isset($data['course_id'])) {
                $course = Course::query()->lockForUpdate()->find((int) $data['course_id']);
                if ($course === null) {
                    $this->fail(404, 'NOT_FOUND', 'La Course indiquée n’existe pas.');
                }
                if (! in_array($course->statut_execution, ['A_VENIR', 'EN_COURS'], true)) {
                    $this->fail(409, 'COURSE_STATE_CONFLICT', 'La Course n’est pas actuellement affectée.');
                }
                $preparation = CoursePreparation::query()
                    ->where('course_id', $course->id)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();
                if ($preparation === null || (int) $preparation->chauffeur_id !== (int) $chauffeur->id
                    || ($vehicle !== null && (int) $preparation->vehicule_id !== (int) $vehicle->id)) {
                    $this->fail(403, 'FORBIDDEN', 'Le signalement doit concerner le chauffeur ou le véhicule de sa Course affectée.');
                }
            } elseif ($vehicle !== null) {
                $this->fail(422, 'VALIDATION_ERROR', 'Une Course actuellement affectée est obligatoire pour signaler un véhicule.');
            }

            $signalement = SignalementTransport::query()->create([
                'reported_by_id' => $reporter->id,
                'chauffeur_id' => $chauffeur->id,
                'vehicule_id' => $vehicle?->id,
                'course_id' => $course?->id,
                'type' => $data['type'],
                'details' => $data['details'],
                'signale_a' => CarbonImmutable::now('UTC'),
            ]);

            return [
                'message' => 'Signalement enregistré.',
                'data' => (new TransportSignalementResource($signalement))->resolve($request),
            ];
        }, 201);
    }

    public function listIndisponibilites(IndexResourceAvailabilityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = IndisponibiliteRessource::query()->orderByDesc('id');

        if (isset($data['type_ressource'])) {
            $data['type_ressource'] === 'VEHICULE'
                ? $query->whereNotNull('vehicule_id')
                : $query->whereNull('vehicule_id');
        }
        if (isset($data['ressource_id'])) {
            if (($data['type_ressource'] ?? null) === 'VEHICULE') {
                $query->where('vehicule_id', $data['ressource_id']);
            } elseif (($data['type_ressource'] ?? null) === 'CHAUFFEUR') {
                $query->where('chauffeur_id', $data['ressource_id'])->whereNull('vehicule_id');
            } else {
                $query->where(function (Builder $resourceQuery) use ($data): void {
                    $resourceQuery->where('vehicule_id', $data['ressource_id'])
                        ->orWhere(function (Builder $driverQuery) use ($data): void {
                            $driverQuery->whereNull('vehicule_id')->where('chauffeur_id', $data['ressource_id']);
                        });
                });
            }
        }
        if (array_key_exists('ouverte', $data)) {
            $request->boolean('ouverte') ? $query->whereNull('fin_effective_a') : $query->whereNotNull('fin_effective_a');
        }

        $page = $query->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'message' => 'Périodes d’indisponibilité récupérées.',
            'data' => ResourceAvailabilityResource::collection($page->getCollection())->resolve($request),
            'meta' => $this->meta($page),
        ]);
    }

    public function showIndisponibilite(Request $request, int $id): JsonResponse
    {
        $period = IndisponibiliteRessource::query()->find($id);
        if ($period === null) {
            $this->fail(404, 'NOT_FOUND', 'La période demandée n’existe pas.');
        }

        return response()->json(['message' => 'Période d’indisponibilité récupérée.', 'data' => (new ResourceAvailabilityResource($period))->resolve($request)]);
    }

    public function createIndisponibilite(StoreResourceAvailabilityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $fingerprintData = [
            'type_ressource' => $data['type_ressource'],
            'ressource_id' => (int) $data['ressource_id'],
            'version_ressource_attendue' => (int) $data['version_ressource_attendue'],
            'debut_a' => CarbonImmutable::parse($data['debut_a'])->utc()->toISOString(),
            'fin_estimee_a' => $data['fin_estimee_a'] === null ? null : CarbonImmutable::parse($data['fin_estimee_a'])->utc()->toISOString(),
            'motif' => $data['motif'],
        ];

        return $this->executeCommand($request, 'create_indisponibilite', null, $fingerprintData, function (string $key) use ($request, $data): array {
            $resource = $this->lockResource($data['type_ressource'], (int) $data['ressource_id']);
            $this->assertResourceVersion($resource, (int) $data['version_ressource_attendue']);
            $period = IndisponibiliteRessource::query()->create([
                'vehicule_id' => $resource instanceof Vehicule ? $resource->id : null,
                'chauffeur_id' => $resource instanceof Chauffeur ? $resource->id : null,
                'created_by_id' => $request->user()->id,
                'debut_a' => CarbonImmutable::parse($data['debut_a'])->utc(),
                'fin_estimee_a' => $data['fin_estimee_a'] === null ? null : CarbonImmutable::parse($data['fin_estimee_a'])->utc(),
                'fin_effective_a' => null,
                'motif' => $data['motif'],
                'lock_version' => 0,
            ]);
            $this->incrementResourceVersion($resource);
            $this->recordResourceEvent($period, $request->user()->id, 'created', null, $this->snapshot($period), null, $key);

            return ['message' => 'Période d’indisponibilité déclarée.', 'data' => (new ResourceAvailabilityResource($period))->resolve($request)];
        }, 201);
    }

    public function updateIndisponibilite(UpdateResourceAvailabilityRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $fingerprintData = [
            'version_attendue' => (int) $data['version_attendue'],
            'debut_a' => CarbonImmutable::parse($data['debut_a'])->utc()->toISOString(),
            'fin_estimee_a' => $data['fin_estimee_a'] === null ? null : CarbonImmutable::parse($data['fin_estimee_a'])->utc()->toISOString(),
            'motif' => $data['motif'],
            'motif_correction' => $data['motif_correction'],
        ];

        return $this->executeCommand($request, 'update_indisponibilite', $id, $fingerprintData, function (string $key) use ($request, $id, $data): array {
            $period = IndisponibiliteRessource::query()->lockForUpdate()->find($id);
            if ($period === null) {
                $this->fail(404, 'NOT_FOUND', 'La période demandée n’existe pas.');
            }
            $this->assertPeriodVersion($period, (int) $data['version_attendue']);
            if ($period->fin_effective_a !== null) {
                $this->fail(409, 'RESOURCE_PERIOD_CLOSED', 'Une période clôturée ne peut pas être corrigée ou rouverte.');
            }

            $before = $this->snapshot($period);
            $resource = $this->lockPeriodResource($period);
            $period->forceFill([
                'debut_a' => CarbonImmutable::parse($data['debut_a'])->utc(),
                'fin_estimee_a' => $data['fin_estimee_a'] === null ? null : CarbonImmutable::parse($data['fin_estimee_a'])->utc(),
                'motif' => $data['motif'],
                'lock_version' => (int) $period->lock_version + 1,
            ])->save();
            $this->incrementResourceVersion($resource);
            $this->recordResourceEvent($period, $request->user()->id, 'corrected', $before, $this->snapshot($period), $data['motif_correction'], $key);

            return ['message' => 'Période d’indisponibilité corrigée.', 'data' => (new ResourceAvailabilityResource($period))->resolve($request)];
        });
    }

    public function confirmReturn(ReturnResourceAvailabilityRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $fingerprintData = [
            'version_attendue' => (int) $data['version_attendue'],
            'fin_effective_a' => CarbonImmutable::parse($data['fin_effective_a'])->utc()->toISOString(),
            'motif' => $data['motif'],
        ];

        return $this->executeCommand($request, 'confirm_return', $id, $fingerprintData, function (string $key) use ($request, $id, $data): array {
            $period = IndisponibiliteRessource::query()->lockForUpdate()->find($id);
            if ($period === null) {
                $this->fail(404, 'NOT_FOUND', 'La période demandée n’existe pas.');
            }
            $this->assertPeriodVersion($period, (int) $data['version_attendue']);
            if ($period->fin_effective_a !== null) {
                $this->fail(409, 'RESOURCE_PERIOD_CLOSED', 'Cette période a déjà été remise en service.');
            }
            $effectiveEnd = CarbonImmutable::parse($data['fin_effective_a'])->utc();
            if ($effectiveEnd->lessThan($period->debut_a)) {
                $this->fail(422, 'VALIDATION_ERROR', 'La fin effective ne peut pas précéder le début de la période.');
            }

            $before = $this->snapshot($period);
            $resource = $this->lockPeriodResource($period);
            $period->forceFill([
                'fin_effective_a' => $effectiveEnd,
                'lock_version' => (int) $period->lock_version + 1,
            ])->save();
            $this->incrementResourceVersion($resource);
            $this->recordResourceEvent($period, $request->user()->id, 'returned', $before, $this->snapshot($period), $data['motif'], $key);

            return ['message' => 'Remise en service enregistrée.', 'data' => (new ResourceAvailabilityResource($period))->resolve($request)];
        });
    }

    private function lockResource(string $type, int $id): Vehicule|Chauffeur
    {
        $resource = $type === 'VEHICULE'
            ? Vehicule::query()->lockForUpdate()->find($id)
            : Chauffeur::query()->lockForUpdate()->find($id);

        if ($resource === null) {
            $this->fail(404, 'NOT_FOUND', 'La ressource demandée n’existe pas.');
        }

        return $resource;
    }

    private function lockPeriodResource(IndisponibiliteRessource $period): Vehicule|Chauffeur
    {
        if ($period->vehicule_id !== null) {
            return $this->lockResource('VEHICULE', (int) $period->vehicule_id);
        }

        return $this->lockResource('CHAUFFEUR', (int) $period->chauffeur_id);
    }

    private function assertResourceVersion(Vehicule|Chauffeur $resource, int $expectedVersion): void
    {
        if ((int) $resource->lock_version !== $expectedVersion) {
            $this->fail(409, 'VERSION_CONFLICT', 'La ressource a changé. Rechargez-la avant de réessayer.');
        }
    }

    private function assertPeriodVersion(IndisponibiliteRessource $period, int $expectedVersion): void
    {
        if ((int) $period->lock_version !== $expectedVersion) {
            $this->fail(409, 'VERSION_CONFLICT', 'La période a changé. Rechargez-la avant de réessayer.');
        }
    }

    private function incrementResourceVersion(Vehicule|Chauffeur $resource): void
    {
        $resource->forceFill(['lock_version' => (int) $resource->lock_version + 1])->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(IndisponibiliteRessource $period): array
    {
        return [
            'id' => (int) $period->id,
            'type_ressource' => $period->vehicule_id === null ? 'CHAUFFEUR' : 'VEHICULE',
            'ressource_id' => (int) ($period->vehicule_id ?? $period->chauffeur_id),
            'debut_a' => $period->debut_a?->toISOString(),
            'fin_estimee_a' => $period->fin_estimee_a?->toISOString(),
            'fin_effective_a' => $period->fin_effective_a?->toISOString(),
            'motif' => $period->motif,
            'lock_version' => (int) $period->lock_version,
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function recordResourceEvent(IndisponibiliteRessource $period, int $actorId, string $action, ?array $before, array $after, ?string $reason, string $key): void
    {
        TransportResourceEvent::query()->create([
            'indisponibilite_ressource_id' => $period->id,
            'actor_id' => $actorId,
            'action' => $action,
            'version' => (int) $period->lock_version,
            'before_state' => $before,
            'after_state' => $after,
            'motif_evenement' => $reason,
            'command_key' => $key,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $fingerprintData @param callable(string): array<string, mixed> $operation */
    private function executeCommand(ApiFormRequest $request, string $action, ?int $id, array $fingerprintData, callable $operation, int $successStatus = 200): JsonResponse
    {
        $key = (string) $request->validated('idempotency_key');
        ksort($fingerprintData);
        $fingerprint = hash('sha256', json_encode([$action, $id, $fingerprintData], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($request, $key, $fingerprint, $operation, $successStatus): JsonResponse {
                $guard = DB::table('course_guards')->where('id', 1)->lockForUpdate()->first();
                if ($guard === null) {
                    $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'La garde de traitement est indisponible.');
                }

                $prior = DB::table('course_commands')
                    ->where('actor_id', $request->user()->id)
                    ->where('command_key', $key)
                    ->first();
                if ($prior !== null) {
                    if (! hash_equals($prior->fingerprint, $fingerprint)) {
                        $this->fail(409, 'IDEMPOTENCY_CONFLICT', 'Cette clé a déjà été utilisée pour une autre intention.');
                    }

                    return response()->json(json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR), (int) $prior->http_status);
                }

                $body = $operation($key);
                DB::table('course_commands')->insert([
                    'actor_id' => $request->user()->id,
                    'command_key' => $key,
                    'fingerprint' => $fingerprint,
                    'http_status' => $successStatus,
                    'response' => json_encode($body, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return response()->json($body, $successStatus);
            }, 3);
        } catch (QueryException $exception) {
            report($exception);
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'Opération temporairement indisponible ; aucun changement partiel validé.');
        }
    }

    /** @return array{current_page: int, per_page: int, total: int, last_page: int} */
    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
