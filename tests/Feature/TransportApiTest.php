<?php

namespace Tests\Feature;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\Demande;
use App\Models\Permission;
use App\Models\SignalementTransport;
use App\Models\TransportResourceEvent;
use App\Models\User;
use App\Models\Vehicule;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class TransportApiTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'aninf_test'
            || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Les tests Transport exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Le serveur n’a pas sélectionné aninf_test.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_transport_routes_match_the_contract_and_require_dedicated_permissions(): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $expected = [
            'get /api/transport/signalements' => 'read_transport_signalement',
            'post /api/transport/signalements' => 'report_course_incident',
            'get /api/transport/signalements/{id}' => 'read_transport_signalement',
            'get /api/transport/indisponibilites' => 'read_resource_availability',
            'post /api/transport/indisponibilites' => 'manage_resource_availability',
            'get /api/transport/indisponibilites/{id}' => 'read_resource_availability',
            'put /api/transport/indisponibilites/{id}' => 'manage_resource_availability',
            'post /api/transport/indisponibilites/{id}/remise-en-service' => 'confirm_resource_return',
        ];
        $actual = [];

        foreach (app('router')->getRoutes() as $route) {
            if (($route->defaults['transport_api'] ?? false) !== true) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[strtolower($method).' /'.$route->uri()] = $route->gatherMiddleware();
            }
        }

        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $operation => $permission) {
            [$method, $path] = explode(' ', $operation, 2);
            $definition = $contract['paths'][$path][$method];
            $this->assertSame('implemented', $definition['x-implementation-status']);
            $this->assertContains($permission, $definition['x-permissions']);
            $this->assertTrue(collect($actual[$operation])->contains(fn (string $middleware): bool => str_contains($middleware, $permission)));
            $this->assertContains('auth:sanctum', $actual[$operation]);
            $this->assertContains('password.changed', $actual[$operation]);
            $this->assertTrue(Permission::query()->where('name', $permission)->exists());
        }

        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route): bool => $route->uri() === 'api/transport/signalements/{id}' && in_array('DELETE', $route->methods(), true)));
        $this->getJson('/api/transport/signalements')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $readerWithoutPermission = $this->userWith('create_course');
        $this->authenticate($readerWithoutPermission);
        $this->getJson('/api/transport/signalements')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_openapi_component_references_and_operation_ids_are_valid(): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
        $operationIds = [];
        foreach ($contract['paths'] as $path) {
            foreach ($path as $method => $operation) {
                if (in_array($method, $methods, true) && isset($operation['operationId'])) {
                    $operationIds[] = $operation['operationId'];
                }
            }
        }
        $this->assertCount(count(array_unique($operationIds)), $operationIds);

        $references = [];
        $walk = function (mixed $value) use (&$walk, &$references): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === '$ref' && is_string($child) && str_starts_with($child, '#/components/')) {
                    $references[] = substr($child, strlen('#/components/'));
                } else {
                    $walk($child);
                }
            }
        };
        $walk($contract);

        foreach ($references as $reference) {
            $component = $contract['components'];
            foreach (explode('/', $reference) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                $this->assertIsArray($component);
                $this->assertArrayHasKey($part, $component, 'Unresolved OpenAPI component reference: '.$reference);
                $component = $component[$part];
            }
        }
    }

    public function test_availability_period_creation_is_versioned_idempotent_and_audited(): void
    {
        $manager = $this->userWith('manage_resource_availability');
        $vehicle = Vehicule::factory()->create(['lock_version' => 0]);
        $this->authenticate($manager);
        $key = (string) Str::uuid();
        $payload = [
            'type_ressource' => 'VEHICULE',
            'ressource_id' => $vehicle->id,
            'version_ressource_attendue' => 0,
            'debut_a' => '2026-11-20T08:00:00Z',
            'fin_estimee_a' => '2026-11-20T12:00:00+00:00',
            'motif' => 'Contrôle atelier',
        ];

        $first = $this->postJson('/api/transport/indisponibilites', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $periodId = $first->json('data.id');
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $this->assertEqualsCanonicalizing(
            array_keys($contract['components']['schemas']['ResourceAvailability']['properties']),
            array_keys($first->json('data')),
        );
        $first->assertJsonPath('data.type_ressource', 'VEHICULE')
            ->assertJsonPath('data.ressource_id', $vehicle->id)
            ->assertJsonPath('data.fin_effective_a', null)
            ->assertJsonPath('data.lock_version', 0);

        $retry = $this->postJson('/api/transport/indisponibilites', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $this->assertEquals($first->json(), $retry->json());
        $this->assertSame(1, DB::table('indisponibilite_ressources')->where('id', $periodId)->count());
        $this->assertSame(1, TransportResourceEvent::query()->where('indisponibilite_ressource_id', $periodId)->count());
        $this->assertSame(1, (int) $vehicle->fresh()->lock_version);
        $this->assertSame(1, DB::table('course_commands')->where('actor_id', $manager->id)->where('command_key', $key)->count());

        $this->putJson('/api/transport/indisponibilites/'.$periodId, [
            'version_attendue' => 0,
            'debut_a' => '2026-11-20T08:00:00Z',
            'fin_estimee_a' => '2026-11-20T12:00:00+00:00',
            'motif' => 'Autre motif',
            'motif_correction' => 'Nouvelle constatation',
        ], ['Idempotency-Key' => $key])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_availability_corrections_and_return_preserve_an_append_only_history(): void
    {
        $manager = $this->userWith(['manage_resource_availability', 'confirm_resource_return', 'read_resource_availability']);
        $driver = Chauffeur::factory()->create(['lock_version' => 0]);
        $period = $this->createPeriod($manager, 'CHAUFFEUR', $driver->id);
        $this->authenticate($manager);

        $this->putJson('/api/transport/indisponibilites/'.$period['id'], [
            'version_attendue' => 0,
            'debut_a' => '2026-11-20T08:30:00+00:00',
            'fin_estimee_a' => '2026-11-20T13:00:00+00:00',
            'motif' => 'Diagnostic prolongé',
            'motif_correction' => 'Durée réévaluée',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.motif', 'Diagnostic prolongé');

        $this->putJson('/api/transport/indisponibilites/'.$period['id'], [
            'version_attendue' => 0,
            'debut_a' => '2026-11-20T08:30:00+00:00',
            'fin_estimee_a' => null,
            'motif' => 'Stale',
            'motif_correction' => 'Conflit',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('code', 'VERSION_CONFLICT');

        $this->postJson('/api/transport/indisponibilites/'.$period['id'].'/remise-en-service', [
            'version_attendue' => 1,
            'fin_effective_a' => '2026-11-20T11:30:00+00:00',
            'motif' => 'Contrôle terminé',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.fin_effective_a', '2026-11-20T11:30:00.000000Z')
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.motif', 'Diagnostic prolongé');

        $this->putJson('/api/transport/indisponibilites/'.$period['id'], [
            'version_attendue' => 2,
            'debut_a' => '2026-11-20T08:30:00+00:00',
            'fin_estimee_a' => null,
            'motif' => 'Réouverture interdite',
            'motif_correction' => 'Test',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('code', 'RESOURCE_PERIOD_CLOSED');

        $this->getJson('/api/transport/indisponibilites?type_ressource=CHAUFFEUR&ressource_id='.$driver->id.'&ouverte=false')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $period['id']);
        $this->assertSame(['created', 'corrected', 'returned'], TransportResourceEvent::query()
            ->where('indisponibilite_ressource_id', $period['id'])
            ->orderBy('id')
            ->pluck('action')
            ->all());
        $createdEvent = TransportResourceEvent::query()->where('action', 'created')->where('indisponibilite_ressource_id', $period['id'])->firstOrFail();
        $this->assertSame('Diagnostic atelier', $createdEvent->after_state['motif']);
        $this->assertSame('Durée réévaluée', TransportResourceEvent::query()->where('action', 'corrected')->where('indisponibilite_ressource_id', $period['id'])->value('motif_evenement'));
        $this->assertSame('Contrôle terminé', TransportResourceEvent::query()->where('action', 'returned')->where('indisponibilite_ressource_id', $period['id'])->value('motif_evenement'));
        $this->assertSame(3, (int) $driver->fresh()->lock_version);
        $this->assertSame(1, DB::table('indisponibilite_ressources')->where('id', $period['id'])->count());
    }

    public function test_open_period_remains_blocking_after_estimated_end_and_until_explicit_return(): void
    {
        $manager = $this->userWith(['manage_resource_availability', 'read_resource_availability']);
        $vehicle = Vehicule::factory()->create();
        $this->travelTo('2026-11-20 16:00:00 UTC');
        $period = $this->createPeriod($manager, 'VEHICULE', $vehicle->id, '2026-11-20T08:00:00+00:00', '2026-11-20T12:00:00+00:00');
        $this->authenticate($manager);

        $this->getJson('/api/transport/indisponibilites?ouverte=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.fin_effective_a', null);
        $this->postJson('/api/transport/indisponibilites/'.$period['id'].'/remise-en-service', [
            'version_attendue' => 0,
            'fin_effective_a' => '2026-11-20T15:30:00+00:00',
            'motif' => 'Retour réel confirmé',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $manager->givePermissionTo('confirm_resource_return');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/transport/indisponibilites/'.$period['id'].'/remise-en-service', [
            'version_attendue' => 0,
            'fin_effective_a' => '2026-11-20T15:30:00+00:00',
            'motif' => 'Retour réel confirmé',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('2026-11-20 15:30:00', $periodModel = DB::table('indisponibilite_ressources')->where('id', $period['id'])->value('fin_effective_a'));
        $this->assertSame(0, DB::table('indisponibilite_ressources')->where('vehicule_id', $vehicle->id)->whereNull('fin_effective_a')->count());
        $this->travelBack();
    }

    public function test_validation_unknown_references_and_pagination_limit_follow_the_contract(): void
    {
        $manager = $this->userWith(['manage_resource_availability', 'read_resource_availability']);
        $this->authenticate($manager);

        $this->postJson('/api/transport/indisponibilites', [
            'type_ressource' => 'VEHICULE',
            'ressource_id' => 999999,
            'version_ressource_attendue' => 0,
            'debut_a' => '2026-11-20T10:00:00+00:00',
            'fin_estimee_a' => '2026-11-20T09:00:00+00:00',
            'motif' => 'Test',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->postJson('/api/transport/indisponibilites', [
            'type_ressource' => 'VEHICULE',
            'ressource_id' => 999999,
            'version_ressource_attendue' => 0,
            'debut_a' => '2026-11-20T10:00:00+00:00',
            'fin_estimee_a' => null,
            'motif' => 'Test',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/transport/indisponibilites?per_page=101')->assertUnprocessable();
        $this->getJson('/api/transport/indisponibilites/999999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/transport/indisponibilites?filtre_inconnu=x')->assertUnprocessable();
    }

    public function test_driver_can_report_only_self_or_a_vehicle_assigned_to_their_active_course(): void
    {
        $driverUser = $this->userWith('report_course_incident');
        $chauffeur = Chauffeur::factory()->create(['user_id' => $driverUser->id, 'statut' => 'ACTIF']);
        $vehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $otherVehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $course = Course::factory()->create(['statut_execution' => 'A_VENIR']);
        $course->preparations()->create([
            'version' => 1,
            'actor_id' => $driverUser->id,
            'chauffeur_id' => $chauffeur->id,
            'vehicule_id' => $vehicle->id,
            'plan' => ['occupation_debut_prevue' => '2026-11-20T08:00:00Z', 'occupation_fin_prevue' => '2026-11-20T12:00:00Z', 'chargement_confirme' => true, 'arrets' => []],
        ]);
        $this->authenticate($driverUser);

        $this->postJson('/api/transport/signalements', [
            'type' => 'RETARD',
            'type_ressource' => 'CHAUFFEUR',
            'ressource_id' => $chauffeur->id,
            'details' => 'Retard sur la route',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.type_ressource', 'CHAUFFEUR')
            ->assertJsonPath('data.ressource_id', $chauffeur->id)
            ->assertJsonPath('data.course_id', null);
        $this->assertSame(0, DB::table('indisponibilite_ressources')->count());
        $this->assertSame('A_VENIR', $course->fresh()->statut_execution);

        $vehicleKey = (string) Str::uuid();
        $report = $this->postJson('/api/transport/signalements', [
            'type' => 'PANNE',
            'type_ressource' => 'VEHICULE',
            'ressource_id' => $vehicle->id,
            'course_id' => $course->id,
            'details' => 'Panne constatée',
        ], ['Idempotency-Key' => $vehicleKey])->assertCreated()
            ->assertJsonPath('data.type_ressource', 'VEHICULE')
            ->assertJsonPath('data.ressource_id', $vehicle->id)
            ->assertJsonPath('data.course_id', $course->id);

        $this->postJson('/api/transport/signalements', [
            'type' => 'PANNE',
            'type_ressource' => 'VEHICULE',
            'ressource_id' => $otherVehicle->id,
            'course_id' => $course->id,
            'details' => 'Véhicule étranger',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $driverUser->givePermissionTo('read_transport_signalement');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/transport/signalements?course_id='.$course->id.'&type_ressource=VEHICULE&ressource_id='.$vehicle->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $report->json('data.id'));
        $this->getJson('/api/transport/signalements/'.$report->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.details', 'Panne constatée');
        $this->getJson('/api/transport/signalements/999999')->assertNotFound();
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $this->assertEqualsCanonicalizing(
            array_keys($contract['components']['schemas']['TransportSignalement']['properties']),
            array_keys($report->json('data')),
        );
        $this->assertSame(2, SignalementTransport::query()->count());
    }

    public function test_signalements_and_transport_events_cannot_be_updated_or_deleted_as_ordinary_models(): void
    {
        $manager = $this->userWith(['manage_resource_availability', 'report_course_incident']);
        $chauffeur = Chauffeur::factory()->create(['user_id' => $manager->id, 'statut' => 'ACTIF']);
        $vehicle = Vehicule::factory()->create();
        $this->authenticate($manager);
        $signalement = $this->postJson('/api/transport/signalements', [
            'type' => 'RETARD',
            'type_ressource' => 'CHAUFFEUR',
            'ressource_id' => $chauffeur->id,
            'details' => 'Signalement append-only',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $period = $this->createPeriod($manager, 'VEHICULE', $vehicle->id);
        $event = TransportResourceEvent::query()->where('indisponibilite_ressource_id', $period['id'])->firstOrFail();
        $report = SignalementTransport::query()->findOrFail($signalement['id']);

        try {
            $report->update(['details' => 'Modifié']);
            $this->fail('La modification d’un signalement aurait dû être refusée.');
        } catch (\LogicException $exception) {
            $this->assertSame('Un signalement Transport est immuable.', $exception->getMessage());
        }
        try {
            $report->delete();
            $this->fail('La suppression d’un signalement aurait dû être refusée.');
        } catch (\LogicException $exception) {
            $this->assertSame('Un signalement Transport est immuable.', $exception->getMessage());
        }
        try {
            $event->update(['action' => 'tampered']);
            $this->fail('La modification d’un événement aurait dû être refusée.');
        } catch (\LogicException $exception) {
            $this->assertSame('Un événement Transport est immuable.', $exception->getMessage());
        }
        try {
            $event->delete();
            $this->fail('La suppression d’un événement aurait dû être refusée.');
        } catch (\LogicException $exception) {
            $this->assertSame('Un événement Transport est immuable.', $exception->getMessage());
        }

        $this->assertSame('Signalement append-only', SignalementTransport::query()->findOrFail($signalement['id'])->details);
        $this->assertSame('created', TransportResourceEvent::query()->findOrFail($event->id)->action);
    }

    public function test_unavailable_resources_cannot_be_confirmed_or_started_on_a_course(): void
    {
        $manager = $this->userWith(['create_course', 'update_course', 'confirm_course', 'manage_resource_availability']);
        $driverUser = $this->userWith('start_course');
        $chauffeur = Chauffeur::factory()->create(['user_id' => $driverUser->id, 'statut' => 'ACTIF']);
        $vehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $demand = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $this->travelTo('2026-11-12 05:00:00 UTC');
        $this->authenticate($manager);

        $prepared = $this->createReadyCourse($demand, $chauffeur, $vehicle);
        $period = $this->createPeriod($manager, 'VEHICULE', $vehicle->id, '2026-11-12T05:00:00+00:00', null);
        $this->postJson('/api/courses/'.$prepared['id'], [
            'version_attendue' => 1,
            'motif' => 'Confirmation avec une ressource indisponible.',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('code', 'RESOURCE_UNAVAILABLE');
        $this->assertNull(Course::query()->findOrFail($prepared['id'])->statut_execution);
        $this->assertSame(Demande::STATUT_EN_ATTENTE, $demand->fresh()->statut);

        $this->postJson('/api/transport/indisponibilites/'.$period['id'].'/remise-en-service', [
            'version_attendue' => 0,
            'fin_effective_a' => '2026-11-12T05:30:00+00:00',
            'motif' => 'Fin avant le départ.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $manager->givePermissionTo('confirm_resource_return');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/transport/indisponibilites/'.$period['id'].'/remise-en-service', [
            'version_attendue' => 0,
            'fin_effective_a' => '2026-11-12T05:30:00+00:00',
            'motif' => 'Fin avant le départ.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $this->postJson('/api/courses/'.$prepared['id'], [
            'version_attendue' => 1,
            'motif' => 'Ressource désormais remise en service.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.statut_execution', 'A_VENIR');

        $laterDriverUser = $this->userWith('start_course');
        $laterChauffeur = Chauffeur::factory()->create(['user_id' => $laterDriverUser->id, 'statut' => 'ACTIF']);
        $laterDemand = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $later = $this->createReadyCourse($laterDemand, $laterChauffeur, Vehicule::factory()->create(['statut' => 'ACTIF']));
        $this->postJson('/api/courses/'.$later['id'], [
            'version_attendue' => 1,
            'motif' => 'Confirmation avant la période.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $laterCourse = Course::query()->findOrFail($later['id']);
        $laterPreparation = $laterCourse->currentPreparation()->first();
        $newPeriod = $this->createPeriod($manager, 'CHAUFFEUR', $laterChauffeur->id, '2026-11-12T05:30:00+00:00', null);

        $this->authenticate($laterDriverUser);
        $this->postJson('/api/courses/'.$later['id'].'/depart', [
            'version_attendue' => 2,
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('code', 'RESOURCE_UNAVAILABLE');
        $this->assertSame('A_VENIR', $laterCourse->fresh()->statut_execution);
        $this->assertSame(1, TransportResourceEvent::query()->where('indisponibilite_ressource_id', $newPeriod['id'])->count());
        $this->travelBack();
    }

    private function createPeriod(User $manager, string $type, int $resourceId, string $start = '2026-11-20T08:00:00+00:00', ?string $estimate = null): array
    {
        $this->authenticate($manager);
        $resourceVersion = $type === 'VEHICULE'
            ? (int) Vehicule::query()->findOrFail($resourceId)->lock_version
            : (int) Chauffeur::query()->findOrFail($resourceId)->lock_version;
        $response = $this->postJson('/api/transport/indisponibilites', [
            'type_ressource' => $type,
            'ressource_id' => $resourceId,
            'version_ressource_attendue' => $resourceVersion,
            'debut_a' => $start,
            'fin_estimee_a' => $estimate,
            'motif' => 'Diagnostic atelier',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return $response->json('data');
    }

    /** @return array<string, mixed> */
    private function createReadyCourse(Demande $demande, Chauffeur $chauffeur, Vehicule $vehicule): array
    {
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->putJson('/api/courses/'.$course['id'], [
            'version_attendue' => 0,
            'demande_ids' => [$demande->id],
            'chauffeur_id' => $chauffeur->id,
            'vehicule_id' => $vehicule->id,
            'plan' => [
                'occupation_debut_prevue' => '2026-11-12T07:30:00+01:00',
                'occupation_fin_prevue' => '2026-11-12T17:00:00+01:00',
                'chargement_confirme' => true,
                'arrets' => [['ordre' => 1, 'lieu' => 'Départ', 'employes' => [], 'objets' => []]],
            ],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $course;
    }

    private function userWith(string|array $permissions): User
    {
        $user = User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
        $user->givePermissionTo((array) $permissions);

        return $user;
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('transport-tests')->plainTextToken);
    }
}
