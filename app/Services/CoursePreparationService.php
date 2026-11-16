<?php

namespace App\Services;

use App\Http\Requests\ConfirmCourseRequest;
use App\Http\Requests\FinishCourseRequest;
use App\Http\Requests\IndexCourseRequest;
use App\Http\Requests\ListTreatmentDemandesRequest;
use App\Http\Requests\StartCourseRequest;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCoursePreparationRequest;
use App\Http\Resources\CourseResource;
use App\Http\Resources\TreatmentDemandeResource;
use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\CoursePreparation;
use App\Models\Demande;
use App\Models\DemandeEmploye;
use App\Models\DemandeObjet;
use App\Models\IndisponibiliteRessource;
use App\Models\User;
use App\Models\Vehicule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CoursePreparationService
{
    public function listPendingDemandes(ListTreatmentDemandesRequest $request): JsonResponse
    {
        $query = Demande::query()
            ->where('statut', Demande::STATUT_EN_ATTENTE)
            ->with(['direction:id,nom', 'service:id,nom,code'])
            ->withCount(['employes', 'objets'])
            ->orderBy('date_depart')->orderBy('heure_depart')->orderBy('id');
        $data = $request->validated();
        if (isset($data['direction_id'])) {
            $query->where('direction_id', $data['direction_id']);
        }
        if (isset($data['date_depart_from'])) {
            $query->whereDate('date_depart', '>=', $data['date_depart_from']);
        }
        if (isset($data['date_depart_to'])) {
            $query->whereDate('date_depart', '<=', $data['date_depart_to']);
        }
        $page = $query->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['message' => 'Demandes en attente récupérées.', 'data' => TreatmentDemandeResource::collection($page->getCollection())->resolve($request), 'meta' => $this->meta($page)]);
    }

    public function listCourses(IndexCourseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = Course::query()->with(['preparations.demandes:id', 'currentPreparation.demandes:id'])
            ->orderByDesc('id')->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['message' => 'Courses récupérées.', 'data' => CourseResource::collection($page->getCollection())->resolve($request), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $course = Course::query()->with(['preparations.demandes:id', 'currentPreparation.demandes:id'])->find($id);
        if ($course === null) {
            $this->fail(404, 'COURSE_NOT_FOUND', 'La Course demandée n’existe pas.');
        }

        return response()->json(['message' => 'Course récupérée.', 'data' => (new CourseResource($course))->resolve($request)]);
    }

    public function create(StoreCourseRequest $request): JsonResponse
    {
        return $this->executeCommand($request, 'create', null, [], function (string $key) use ($request): array {
            $course = Course::query()->create(['created_by_id' => $request->user()->id, 'statut_execution' => null, 'lock_version' => 0]);
            $preparation = $course->preparations()->create(['version' => 1, 'actor_id' => $request->user()->id]);
            $this->recordEvent($course->id, $request->user()->id, 'created', 1, [], $key);
            $course->load(['preparations.demandes:id', 'currentPreparation.demandes:id']);

            return ['message' => 'Préparation de Course créée.', 'data' => (new CourseResource($course))->resolve($request)];
        }, 201);
    }

    public function updatePreparation(UpdateCoursePreparationRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['demande_ids']);
        sort($ids, SORT_NUMERIC);
        $fingerprintData = ['version_attendue' => (int) $data['version_attendue'], 'demande_ids' => $ids];
        foreach (['chauffeur_id', 'vehicule_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $fingerprintData[$field] = $data[$field] === null ? null : (int) $data[$field];
            }
        }
        if (array_key_exists('plan', $data)) {
            $fingerprintData['plan'] = $data['plan'] === null ? null : $this->normalizePlan($data['plan']);
        }

        return $this->executeCommand($request, 'update_preparation', $id, $fingerprintData, function (string $key) use ($request, $id, $ids, $data, $fingerprintData): array {
            $course = Course::query()->lockForUpdate()->find($id);
            if ($course === null) {
                $this->fail(404, 'COURSE_NOT_FOUND', 'La Course demandée n’existe pas.');
            }
            if ((int) $course->lock_version !== (int) $data['version_attendue']) {
                $this->fail(409, 'VERSION_CONFLICT', 'La préparation a changé. Rechargez la Course avant de réessayer.');
            }
            if ($course->statut_execution !== null) {
                $this->fail(409, 'COURSE_STATE_CONFLICT', 'Une Course confirmée ne peut plus être modifiée comme préparation.');
            }
            $demandes = Demande::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($demandes->count() !== count($ids)) {
                $this->fail(404, 'DEMANDE_NOT_FOUND', 'Une ou plusieurs demandes n’existent pas.');
            }
            if ($demandes->contains(fn (Demande $demande): bool => $demande->statut !== Demande::STATUT_EN_ATTENTE)) {
                $this->fail(409, 'DEMANDE_NOT_ELIGIBLE', 'Seules les demandes EN_ATTENTE peuvent être ajoutées à une préparation.');
            }

            $current = $course->currentPreparation()->with('demandes:id')->first();
            $currentIds = $current?->demandes->pluck('id')->map(fn ($value): int => (int) $value)->all() ?? [];
            sort($currentIds, SORT_NUMERIC);
            $chauffeurId = $current?->chauffeur_id === null ? null : (int) $current->chauffeur_id;
            $vehiculeId = $current?->vehicule_id === null ? null : (int) $current->vehicule_id;
            $currentPlan = $current?->plan === null ? null : $this->normalizePlan($current->plan);
            $plan = array_key_exists('plan', $data) ? ($data['plan'] === null ? null : $fingerprintData['plan']) : $currentPlan;
            $this->assertPlanReferences($plan, $ids);

            if (array_key_exists('chauffeur_id', $data)) {
                $chauffeurId = $data['chauffeur_id'] === null ? null : $this->eligibleChauffeurId((int) $data['chauffeur_id']);
            }
            if (array_key_exists('vehicule_id', $data)) {
                $vehiculeId = $data['vehicule_id'] === null ? null : $this->eligibleVehiculeId((int) $data['vehicule_id']);
            }

            if ($ids !== $currentIds || $chauffeurId !== ($current?->chauffeur_id === null ? null : (int) $current->chauffeur_id) || $vehiculeId !== ($current?->vehicule_id === null ? null : (int) $current->vehicule_id) || $plan !== $currentPlan) {
                $version = (int) ($current?->version ?? 0) + 1;
                $course->forceFill(['lock_version' => (int) $course->lock_version + 1])->save();
                $preparation = $course->preparations()->create([
                    'version' => $version,
                    'actor_id' => $request->user()->id,
                    'chauffeur_id' => $chauffeurId,
                    'vehicule_id' => $vehiculeId,
                    'plan' => $plan,
                ]);
                if ($ids !== []) {
                    $preparation->demandes()->sync($ids);
                }
                $this->recordEvent($course->id, $request->user()->id, 'preparation_updated', $version, $ids, $key);
            }
            $course->load(['preparations.demandes:id', 'currentPreparation.demandes:id']);

            return ['message' => 'Préparation de Course mise à jour.', 'data' => (new CourseResource($course))->resolve($request)];
        }, 200);
    }

    public function confirm(ConfirmCourseRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        return $this->executeCommand($request, 'confirm', $id, ['version_attendue' => (int) $data['version_attendue'], 'motif' => $data['motif']], function (string $key) use ($request, $id, $data): array {
            $course = Course::query()->lockForUpdate()->find($id);
            if ($course === null) {
                $this->fail(404, 'COURSE_NOT_FOUND', 'La Course demandée n’existe pas.');
            }
            $this->assertExpectedVersion($course, (int) $data['version_attendue']);
            if ($course->statut_execution !== null) {
                $this->fail(409, 'COURSE_STATE_CONFLICT', 'Seule une préparation non confirmée peut être confirmée.');
            }

            $preparation = CoursePreparation::query()->where('course_id', $id)->orderByDesc('version')->lockForUpdate()->first();
            if ($preparation === null || $preparation->chauffeur_id === null || $preparation->vehicule_id === null || $preparation->plan === null) {
                $this->fail(409, 'COURSE_NOT_READY', 'La préparation doit contenir des demandes, un chauffeur, un véhicule et un plan complet.');
            }
            $plan = $preparation->plan;
            if (($plan['occupation_debut_prevue'] ?? null) === null || ($plan['occupation_fin_prevue'] ?? null) === null || ($plan['chargement_confirme'] ?? false) !== true || ($plan['arrets'] ?? []) === []) {
                $this->fail(409, 'COURSE_NOT_READY', 'La période d’occupation, la validation manuelle du chargement et au moins un arrêt sont obligatoires.');
            }

            $demandes = $preparation->demandes()->orderBy('demandes.id')->lockForUpdate()->get();
            if ($demandes->isEmpty()) {
                $this->fail(409, 'COURSE_NOT_READY', 'Au moins une demande doit être sélectionnée.');
            }
            if ($demandes->contains(fn (Demande $demande): bool => $demande->statut !== Demande::STATUT_EN_ATTENTE)) {
                $this->fail(409, 'DEMANDE_NOT_ELIGIBLE', 'Toutes les demandes doivent être EN_ATTENTE au moment de la confirmation.');
            }

            $chauffeurId = $this->eligibleChauffeurId((int) $preparation->chauffeur_id);
            $vehiculeId = $this->eligibleVehiculeId((int) $preparation->vehicule_id);
            $this->ensureResourcesAreAvailable(
                $chauffeurId,
                $vehiculeId,
                CarbonImmutable::parse($plan['occupation_debut_prevue'])->utc(),
                CarbonImmutable::parse($plan['occupation_fin_prevue'])->utc(),
            );
            $this->ensureResourcesDoNotConflict($course, $chauffeurId, $vehiculeId, $plan);

            $newVersion = (int) $course->lock_version + 1;
            $course->forceFill(['statut_execution' => 'A_VENIR', 'lock_version' => $newVersion])->save();
            foreach ($demandes as $demande) {
                $demande->update(['statut' => Demande::STATUT_VALIDEE]);
                $demande->historiques()->create([
                    'ancien_statut' => Demande::STATUT_EN_ATTENTE,
                    'nouveau_statut' => Demande::STATUT_VALIDEE,
                    'utilisateur_id' => $request->user()->id,
                    'commentaire' => $data['motif'],
                ]);
            }
            $this->recordEvent($course->id, $request->user()->id, 'confirmed', $newVersion, $demandes->modelKeys(), $key);

            $course->load(['preparations.demandes:id', 'currentPreparation.demandes:id']);

            return ['message' => 'Course confirmée.', 'data' => (new CourseResource($course))->resolve($request)];
        });
    }

    public function start(StartCourseRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        return $this->executeCommand($request, 'start', $id, ['version_attendue' => (int) $data['version_attendue']], function (string $key) use ($request, $id, $data): array {
            $course = Course::query()->lockForUpdate()->find($id);
            if ($course === null) {
                $this->fail(404, 'COURSE_NOT_FOUND', 'La Course demandée n’existe pas.');
            }
            $this->assertExpectedVersion($course, (int) $data['version_attendue']);
            if ($course->statut_execution !== 'A_VENIR') {
                $this->fail(409, 'COURSE_STATE_CONFLICT', 'Seule une Course confirmée A_VENIR peut être démarrée.');
            }

            $preparation = $course->currentPreparation()->lockForUpdate()->first();
            if ($preparation === null) {
                $this->fail(409, 'COURSE_NOT_READY', 'La version confirmée est introuvable.');
            }
            $this->assertAssignedDriver($preparation, $request->user());
            $chauffeurId = $this->eligibleChauffeurId((int) $preparation->chauffeur_id);
            $vehiculeId = $this->eligibleVehiculeId((int) $preparation->vehicule_id);
            $now = CarbonImmutable::now('UTC');
            $plannedEnd = CarbonImmutable::parse($preparation->plan['occupation_fin_prevue'])->utc();
            $availabilityEnd = $plannedEnd->greaterThan($now) ? $plannedEnd : $now->addSecond();
            $this->ensureResourcesAreAvailable($chauffeurId, $vehiculeId, $now, $availabilityEnd);
            $this->ensureNoActiveExecutionForResources($course, $chauffeurId, $vehiculeId);

            $newVersion = (int) $course->lock_version + 1;
            $course->forceFill(['statut_execution' => 'EN_COURS', 'demarree_a' => now(), 'lock_version' => $newVersion])->save();
            $this->recordEvent($course->id, $request->user()->id, 'started', $newVersion, $preparation->demandes()->pluck('demandes.id')->map(fn ($value): int => (int) $value)->all(), $key);
            $course->load(['preparations.demandes:id', 'currentPreparation.demandes:id']);

            return ['message' => 'Course démarrée.', 'data' => (new CourseResource($course))->resolve($request)];
        });
    }

    public function finish(FinishCourseRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $fingerprintData = ['version_attendue' => (int) $data['version_attendue']];
        foreach (['fin_reelle', 'chauffeur_libere_a', 'vehicule_libere_a'] as $field) {
            $fingerprintData[$field] = CarbonImmutable::parse($data[$field])->utc()->toISOString();
        }

        return $this->executeCommand($request, 'finish', $id, $fingerprintData, function (string $key) use ($request, $id, $data): array {
            $course = Course::query()->lockForUpdate()->find($id);
            if ($course === null) {
                $this->fail(404, 'COURSE_NOT_FOUND', 'La Course demandée n’existe pas.');
            }
            $this->assertExpectedVersion($course, (int) $data['version_attendue']);
            if ($course->statut_execution !== 'EN_COURS' || $course->demarree_a === null) {
                $this->fail(409, 'COURSE_STATE_CONFLICT', 'Seule une Course EN_COURS peut être terminée.');
            }

            $preparation = $course->currentPreparation()->lockForUpdate()->first();
            if ($preparation === null) {
                $this->fail(409, 'COURSE_NOT_READY', 'La version confirmée est introuvable.');
            }
            $this->assertAssignedDriver($preparation, $request->user());

            $finishedAt = CarbonImmutable::parse($data['fin_reelle'])->utc();
            $driverReleasedAt = CarbonImmutable::parse($data['chauffeur_libere_a'])->utc();
            $vehicleReleasedAt = CarbonImmutable::parse($data['vehicule_libere_a'])->utc();
            $startedAt = CarbonImmutable::parse($course->demarree_a)->utc();
            $now = CarbonImmutable::now('UTC');
            if ($finishedAt->lessThan($startedAt) || $driverReleasedAt->lessThan($finishedAt) || $vehicleReleasedAt->lessThan($finishedAt) || $finishedAt->greaterThan($now) || $driverReleasedAt->greaterThan($now) || $vehicleReleasedAt->greaterThan($now)) {
                $this->fail(422, 'VALIDATION_ERROR', 'Les heures réelles doivent être cohérentes avec le départ et ne pas être futures.');
            }

            $newVersion = (int) $course->lock_version + 1;
            $course->forceFill([
                'statut_execution' => 'TERMINEE',
                'terminee_a' => $finishedAt,
                'chauffeur_libere_a' => $driverReleasedAt,
                'vehicule_libere_a' => $vehicleReleasedAt,
                'lock_version' => $newVersion,
            ])->save();
            $this->recordEvent($course->id, $request->user()->id, 'finished', $newVersion, $preparation->demandes()->pluck('demandes.id')->map(fn ($value): int => (int) $value)->all(), $key);
            $course->load(['preparations.demandes:id', 'currentPreparation.demandes:id']);

            return ['message' => 'Course terminée et libération des ressources attestée.', 'data' => (new CourseResource($course))->resolve($request)];
        });
    }

    private function assertExpectedVersion(Course $course, int $expectedVersion): void
    {
        if ((int) $course->lock_version !== $expectedVersion) {
            $this->fail(409, 'VERSION_CONFLICT', 'La Course a changé. Rechargez-la avant de réessayer.');
        }
    }

    private function assertAssignedDriver(CoursePreparation $preparation, User $user): void
    {
        $chauffeur = Chauffeur::query()->lockForUpdate()->find((int) $preparation->chauffeur_id);
        if ($chauffeur === null || $chauffeur->user_id !== $user->id || $chauffeur->statut !== 'ACTIF' || $user->statut !== 'ACTIF') {
            $this->fail(404, 'COURSE_NOT_FOUND', 'La Course demandée n’existe pas.');
        }
    }

    /** @param array<string, mixed> $plan */
    private function ensureResourcesDoNotConflict(Course $course, int $chauffeurId, int $vehiculeId, array $plan): void
    {
        $start = new \DateTimeImmutable($plan['occupation_debut_prevue']);
        $end = new \DateTimeImmutable($plan['occupation_fin_prevue']);
        $activeCourses = Course::query()->where('id', '<>', $course->id)->whereIn('statut_execution', ['A_VENIR', 'EN_COURS'])->with('currentPreparation')->lockForUpdate()->get();

        foreach ($activeCourses as $activeCourse) {
            $preparation = $activeCourse->currentPreparation;
            if ($preparation === null) {
                continue;
            }
            $sameDriver = (int) $preparation->chauffeur_id === $chauffeurId;
            $sameVehicle = (int) $preparation->vehicule_id === $vehiculeId;
            if (! $sameDriver && ! $sameVehicle) {
                continue;
            }
            if ($activeCourse->statut_execution === 'EN_COURS') {
                $this->fail(409, 'RESOURCE_CONFLICT', 'Une ressource sélectionnée est encore mobilisée par une Course en cours.');
            }
            $occupiedPlan = $preparation->plan;
            if (($occupiedPlan['occupation_debut_prevue'] ?? null) === null || ($occupiedPlan['occupation_fin_prevue'] ?? null) === null) {
                $this->fail(409, 'RESOURCE_CONFLICT', 'Une réservation existante ne possède pas de période vérifiable.');
            }
            $occupiedStart = new \DateTimeImmutable($occupiedPlan['occupation_debut_prevue']);
            $occupiedEnd = new \DateTimeImmutable($occupiedPlan['occupation_fin_prevue']);
            if ($start < $occupiedEnd && $end > $occupiedStart) {
                $this->fail(409, 'RESOURCE_CONFLICT', 'Une ressource sélectionnée est déjà réservée sur cette période.');
            }
        }
    }

    private function ensureResourcesAreAvailable(int $chauffeurId, int $vehiculeId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $periodExists = IndisponibiliteRessource::query()
            ->where(function ($query) use ($chauffeurId, $vehiculeId): void {
                $query->where('chauffeur_id', $chauffeurId)
                    ->orWhere('vehicule_id', $vehiculeId);
            })
            ->where('debut_a', '<', $end->toDateTimeString())
            ->where(function ($query) use ($start): void {
                $query->whereNull('fin_effective_a')
                    ->orWhere('fin_effective_a', '>', $start->toDateTimeString());
            })
            ->exists();

        if ($periodExists) {
            $this->fail(409, 'RESOURCE_UNAVAILABLE', 'Une indisponibilité effective ou ouverte empêche l’utilisation d’une ressource sur cette période.');
        }
    }

    private function ensureNoActiveExecutionForResources(Course $course, int $chauffeurId, int $vehiculeId): void
    {
        $activeCourses = Course::query()->where('id', '<>', $course->id)->where('statut_execution', 'EN_COURS')->with('currentPreparation')->lockForUpdate()->get();
        foreach ($activeCourses as $activeCourse) {
            $preparation = $activeCourse->currentPreparation;
            if ($preparation !== null && ((int) $preparation->chauffeur_id === $chauffeurId || (int) $preparation->vehicule_id === $vehiculeId)) {
                $this->fail(409, 'RESOURCE_CONFLICT', 'Une ressource sélectionnée est encore mobilisée par une Course en cours.');
            }
        }
    }

    /** @param array<string, mixed>|null $plan @param list<int> $demandeIds */
    private function assertPlanReferences(?array $plan, array $demandeIds): void
    {
        if ($plan === null) {
            return;
        }
        $employeeIds = collect($plan['arrets'])->flatMap(fn (array $stop): array => collect($stop['employes'])->pluck('demande_employe_id')->all())->unique()->values()->all();
        if ($employeeIds !== [] && DemandeEmploye::query()->whereIn('demande_id', $demandeIds)->whereIn('id', $employeeIds)->count() !== count($employeeIds)) {
            $this->fail(404, 'COURSE_PLAN_REFERENCE_NOT_FOUND', 'Un employé du plan ne fait pas partie des demandes sélectionnées.');
        }
        $objectIds = collect($plan['arrets'])->flatMap(fn (array $stop): array => collect($stop['objets'])->pluck('demande_objet_id')->all())->unique()->values()->all();
        if ($objectIds !== [] && DemandeObjet::query()->whereIn('demande_id', $demandeIds)->whereIn('id', $objectIds)->count() !== count($objectIds)) {
            $this->fail(404, 'COURSE_PLAN_REFERENCE_NOT_FOUND', 'Un objet du plan ne fait pas partie des demandes sélectionnées.');
        }
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function normalizePlan(array $plan): array
    {
        $stops = array_map(function (array $stop): array {
            $employees = array_map(fn (array $employee): array => ['demande_employe_id' => (int) $employee['demande_employe_id'], 'action' => $employee['action']], $stop['employes'] ?? []);
            $objects = array_map(fn (array $object): array => ['demande_objet_id' => (int) $object['demande_objet_id'], 'action' => $object['action']], $stop['objets'] ?? []);
            usort($employees, fn (array $first, array $second): int => [$first['demande_employe_id'], $first['action']] <=> [$second['demande_employe_id'], $second['action']]);
            usort($objects, fn (array $first, array $second): int => [$first['demande_objet_id'], $first['action']] <=> [$second['demande_objet_id'], $second['action']]);

            return [
                'ordre' => (int) $stop['ordre'],
                'lieu' => $stop['lieu'],
                'heure_prevue' => isset($stop['heure_prevue']) && $stop['heure_prevue'] !== null ? (new \DateTimeImmutable($stop['heure_prevue']))->format(DATE_ATOM) : null,
                'employes' => $employees,
                'objets' => $objects,
            ];
        }, $plan['arrets'] ?? []);
        usort($stops, fn (array $first, array $second): int => $first['ordre'] <=> $second['ordre']);

        return [
            'occupation_debut_prevue' => isset($plan['occupation_debut_prevue']) && $plan['occupation_debut_prevue'] !== null ? (new \DateTimeImmutable($plan['occupation_debut_prevue']))->format(DATE_ATOM) : null,
            'occupation_fin_prevue' => isset($plan['occupation_fin_prevue']) && $plan['occupation_fin_prevue'] !== null ? (new \DateTimeImmutable($plan['occupation_fin_prevue']))->format(DATE_ATOM) : null,
            'chargement_confirme' => array_key_exists('chargement_confirme', $plan) && $plan['chargement_confirme'] !== null ? (bool) $plan['chargement_confirme'] : null,
            'arrets' => $stops,
        ];
    }

    private function eligibleChauffeurId(int $id): int
    {
        $chauffeur = Chauffeur::query()->lockForUpdate()->find($id);
        if ($chauffeur === null) {
            $this->fail(404, 'CHAUFFEUR_NOT_FOUND', 'Le profil Chauffeur demandé n’existe pas.');
        }
        if ($chauffeur->statut !== 'ACTIF' || $chauffeur->user_id === null) {
            $this->fail(409, 'RESOURCE_NOT_ELIGIBLE', 'Le profil Chauffeur doit être actif et lié à un compte utilisateur actif.');
        }
        $user = User::query()->lockForUpdate()->find($chauffeur->user_id);
        if ($user === null || $user->statut !== 'ACTIF') {
            $this->fail(409, 'RESOURCE_NOT_ELIGIBLE', 'Le profil Chauffeur doit être actif et lié à un compte utilisateur actif.');
        }

        return (int) $chauffeur->id;
    }

    private function eligibleVehiculeId(int $id): int
    {
        $vehicule = Vehicule::query()->lockForUpdate()->find($id);
        if ($vehicule === null) {
            $this->fail(404, 'VEHICULE_NOT_FOUND', 'Le profil Véhicule demandé n’existe pas.');
        }
        if ($vehicule->statut !== 'ACTIF') {
            $this->fail(409, 'RESOURCE_NOT_ELIGIBLE', 'Le profil Véhicule doit être actif pour être sélectionné.');
        }

        return (int) $vehicule->id;
    }

    /** @param array<string, mixed> $fingerprintData
     * @param  callable(string): array<string, mixed>  $operation
     */
    private function executeCommand(Request $request, string $action, ?int $id, array $fingerprintData, callable $operation, int $successStatus = 200): JsonResponse
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
                $prior = DB::table('course_commands')->where('actor_id', $request->user()->id)->where('command_key', $key)->first();
                if ($prior !== null) {
                    if (! hash_equals($prior->fingerprint, $fingerprint)) {
                        $this->fail(409, 'IDEMPOTENCY_CONFLICT', 'Cette clé a déjà été utilisée pour une autre intention.');
                    }

                    return response()->json(json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR), (int) $prior->http_status);
                }
                $body = $operation($key);
                $status = $successStatus;
                DB::table('course_commands')->insert(['actor_id' => $request->user()->id, 'command_key' => $key, 'fingerprint' => $fingerprint, 'http_status' => $status, 'response' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return response()->json($body, $status);
            }, 3);
        } catch (QueryException $exception) {
            report($exception);
            $this->fail(503, 'TEMPORARILY_UNAVAILABLE', 'Opération temporairement indisponible ; aucun changement partiel validé.');
        }
    }

    /** @param list<int> $ids */
    private function recordEvent(int $courseId, int $actorId, string $action, int $version, array $ids, string $key): void
    {
        DB::table('course_events')->insert(['course_id' => $courseId, 'actor_id' => $actorId, 'action' => $action, 'version' => $version, 'demande_ids' => json_encode($ids, JSON_THROW_ON_ERROR), 'command_key' => $key, 'created_at' => now()]);
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
