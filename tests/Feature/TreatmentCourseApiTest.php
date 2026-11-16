<?php

namespace Tests\Feature;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\Demande;
use App\Models\DemandeEmploye;
use App\Models\DemandeObjet;
use App\Models\Direction;
use App\Models\Permission;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicule;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class TreatmentCourseApiTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Les tests Traitement exigent exclusivement mysql/aninf_test, sans DB_URL.');
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

    public function test_contract_routes_and_permission_names_match_the_implemented_v1_slice(): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $expected = [
            'get /api/traitement/demandes' => 'read_demande_traitement',
            'get /api/courses' => 'read_course',
            'post /api/courses' => 'create_course',
            'get /api/courses/{id}' => 'read_course',
            'put /api/courses/{id}' => 'update_course',
            'post /api/courses/{id}' => 'confirm_course',
            'post /api/courses/{id}/depart' => 'start_course',
            'post /api/courses/{id}/terminer' => 'finish_course',
        ];
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (($route->defaults['treatment_api'] ?? false) !== true) {
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
            $this->assertTrue(collect($actual[$operation])->contains(fn ($middleware): bool => str_contains($middleware, $permission)));
            $this->assertContains('auth:sanctum', $actual[$operation]);
            $this->assertContains('password.changed', $actual[$operation]);
        }
        $this->assertTrue(Permission::query()->whereIn('name', array_values($expected))->exists());
    }

    public function test_pending_demande_listing_is_filtered_paginated_and_does_not_expose_personal_details(): void
    {
        $user = $this->userWith('read_demande_traitement');
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $pending = Demande::factory()->create(['direction_id' => $direction->id, 'service_id' => $service->id, 'statut' => Demande::STATUT_EN_ATTENTE]);
        Demande::factory()->create(['direction_id' => $direction->id, 'statut' => Demande::STATUT_BROUILLON]);
        $this->authenticate($user);

        $response = $this->getJson('/api/traitement/demandes?direction_id='.$direction->id.'&per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.statut', 'EN_ATTENTE')
            ->assertJsonPath('data.0.direction.id', $direction->id)
            ->assertJsonPath('data.0.service.id', $service->id)
            ->assertJsonPath('meta.per_page', 1);
        $this->assertArrayNotHasKey('demandeur', $response->json('data.0'));
        $this->assertArrayNotHasKey('employes', $response->json('data.0'));
        $this->assertArrayNotHasKey('objets', $response->json('data.0'));
        $this->assertSame(['id', 'reference', 'statut', 'direction', 'service', 'date_depart', 'heure_depart', 'personnes_count', 'objets_count'], array_keys($response->json('data.0')));
        $this->assertSame(Demande::STATUT_EN_ATTENTE, $pending->fresh()->statut);
    }

    public function test_course_routes_enforce_authentication_permissions_validation_and_pagination_limit(): void
    {
        $this->getJson('/api/courses')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $user = $this->userWith('read_demande_traitement');
        $this->authenticate($user);
        $this->getJson('/api/courses')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $user->givePermissionTo('read_course');
        $user->forceFill(['must_change_password' => true])->save();
        $this->assertTrue($user->fresh()->must_change_password);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/courses')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/traitement/demandes?per_page=101')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->getJson('/api/courses?per_page=101')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_course_creation_is_idempotent_and_starts_as_unreserved_preparation(): void
    {
        $user = $this->userWith(['create_course', 'read_course']);
        $this->authenticate($user);
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/courses', [], ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.statut_execution', null)
            ->assertJsonPath('data.lock_version', 0)
            ->assertJsonPath('data.version_preparation', 1)
            ->assertJsonPath('data.demande_ids', [])
            ->assertJsonPath('data.chauffeur_id', null)
            ->assertJsonPath('data.vehicule_id', null)
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.preparations.0.plan', null)
            ->assertJsonPath('data.preparations.0.demande_ids', [])
            ->assertJsonPath('data.preparations.0.chauffeur_id', null)
            ->assertJsonPath('data.preparations.0.vehicule_id', null);
        $id = $first->json('data.id');
        $this->assertSame(['id', 'statut_execution', 'lock_version', 'version_preparation', 'demande_ids', 'chauffeur_id', 'vehicule_id', 'plan', 'demarree_a', 'terminee_a', 'chauffeur_libere_a', 'vehicule_libere_a', 'preparations', 'created_by_id', 'created_at', 'updated_at'], array_keys($first->json('data')));
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $this->assertEqualsCanonicalizing(array_keys($contract['components']['schemas']['Course']['properties']), array_keys($first->json('data')));
        $this->assertEqualsCanonicalizing(array_keys($contract['components']['schemas']['CoursePreparationVersion']['properties']), array_keys($first->json('data.preparations.0')));
        $this->assertArrayHasKey('plan', $contract['components']['schemas']['CoursePreparationUpdate']['properties']);
        $this->getJson('/api/courses')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
        $this->postJson('/api/courses', [], ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Course::query()->count());
        $this->assertSame(1, DB::table('course_events')->where('course_id', $id)->count());
        $this->postJson('/api/courses', [], ['Idempotency-Key' => 'bad-key'])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_idempotency_key_cannot_be_reused_for_another_course_command(): void
    {
        $user = $this->userWith(['create_course', 'update_course', 'read_course']);
        $this->authenticate($user);
        $key = (string) Str::uuid();
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => $key])->assertCreated()->json('data');

        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => []], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_preparation_update_keeps_prior_version_and_never_changes_demande_status(): void
    {
        $user = $this->userWith(['create_course', 'update_course', 'read_course']);
        $direction = Direction::factory()->create();
        $demandes = Demande::factory()->count(2)->create(['direction_id' => $direction->id, 'statut' => Demande::STATUT_EN_ATTENTE]);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $response = $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => $demandes->pluck('id')->all()], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.lock_version', 1)->assertJsonPath('data.version_preparation', 2)->assertJsonCount(2, 'data.preparations');
        $this->assertEqualsCanonicalizing($demandes->pluck('id')->all(), $response->json('data.demande_ids'));
        $this->assertSame(1, DB::table('course_events')->where('course_id', $course['id'])->where('action', 'created')->count());
        $this->assertSame(2, DB::table('course_events')->where('course_id', $course['id'])->count());
        $this->assertSame(2, Demande::query()->whereIn('id', $demandes->pluck('id'))->where('statut', Demande::STATUT_EN_ATTENTE)->count());
        $this->getJson('/api/courses/'.$course['id'])->assertOk()->assertJsonPath('data.lock_version', 1);
    }

    public function test_stale_version_unknown_references_and_ineligible_demandes_return_contract_errors(): void
    {
        $user = $this->userWith(['create_course', 'update_course', 'read_course']);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 1, 'demande_ids' => []], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [999999999]], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNotFound()->assertJsonPath('code', 'DEMANDE_NOT_FOUND');
        $draft = Demande::factory()->create(['statut' => Demande::STATUT_BROUILLON]);
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [$draft->id]], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'DEMANDE_NOT_ELIGIBLE');
        $this->getJson('/api/courses/999999999')->assertNotFound()->assertJsonPath('code', 'COURSE_NOT_FOUND');
    }

    public function test_candidate_resources_are_versioned_preserved_when_omitted_and_clearable_when_null(): void
    {
        $user = $this->userWith(['create_course', 'update_course', 'read_course']);
        $chauffeurUser = User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
        $chauffeur = Chauffeur::factory()->create(['user_id' => $chauffeurUser->id, 'statut' => 'ACTIF']);
        $vehicule = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $selection = $this->putJson('/api/courses/'.$course['id'], [
            'version_attendue' => 0,
            'demande_ids' => [],
            'chauffeur_id' => $chauffeur->id,
            'vehicule_id' => $vehicule->id,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.version_preparation', 2)
            ->assertJsonPath('data.chauffeur_id', $chauffeur->id)
            ->assertJsonPath('data.vehicule_id', $vehicule->id)
            ->assertJsonPath('data.preparations.1.chauffeur_id', $chauffeur->id)
            ->assertJsonPath('data.preparations.1.vehicule_id', $vehicule->id);

        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 1, 'demande_ids' => []], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.chauffeur_id', $chauffeur->id)
            ->assertJsonPath('data.vehicule_id', $vehicule->id);
        $this->assertSame(2, $this->getJson('/api/courses/'.$course['id'])->json('data.version_preparation'));

        $cleared = $this->putJson('/api/courses/'.$course['id'], [
            'version_attendue' => 1,
            'demande_ids' => [],
            'chauffeur_id' => null,
            'vehicule_id' => null,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.version_preparation', 3)
            ->assertJsonPath('data.chauffeur_id', null)
            ->assertJsonPath('data.vehicule_id', null)
            ->assertJsonPath('data.preparations.1.chauffeur_id', $chauffeur->id)
            ->assertJsonPath('data.preparations.1.vehicule_id', $vehicule->id)
            ->assertJsonPath('data.preparations.2.chauffeur_id', null)
            ->assertJsonPath('data.preparations.2.vehicule_id', null);

        $this->assertNull($cleared->json('data.statut_execution'));
        $this->assertSame('ACTIF', $chauffeur->fresh()->statut);
        $this->assertSame('ACTIF', $vehicule->fresh()->statut);
        $this->assertSame(3, DB::table('course_preparations')->where('course_id', $course['id'])->count());
    }

    public function test_manual_route_plan_is_versioned_and_does_not_confirm_or_change_statuses(): void
    {
        $user = $this->userWith(['create_course', 'update_course', 'read_course']);
        $demande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $employee = DemandeEmploye::factory()->create(['demande_id' => $demande->id]);
        $object = DemandeObjet::factory()->create(['demande_id' => $demande->id]);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $plan = [
            'occupation_debut_prevue' => '2026-11-12T07:30:00+01:00',
            'occupation_fin_prevue' => '2026-11-12T17:00:00+01:00',
            'chargement_confirme' => true,
            'arrets' => [
                ['ordre' => 2, 'lieu' => 'Lieu de dépose', 'heure_prevue' => '2026-11-12T09:00:00+01:00', 'employes' => [['demande_employe_id' => $employee->id, 'action' => 'DEPOSE']], 'objets' => [['demande_objet_id' => $object->id, 'action' => 'DEPOSE']]],
                ['ordre' => 1, 'lieu' => 'Lieu de prise en charge', 'heure_prevue' => '2026-11-12T08:00:00+01:00', 'employes' => [['demande_employe_id' => $employee->id, 'action' => 'PRISE_EN_CHARGE']], 'objets' => [['demande_objet_id' => $object->id, 'action' => 'PRISE_EN_CHARGE']]],
            ],
        ];

        $response = $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [$demande->id], 'plan' => $plan], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.lock_version', 1)->assertJsonPath('data.version_preparation', 2)
            ->assertJsonPath('data.plan.chargement_confirme', true)
            ->assertJsonPath('data.plan.arrets.0.ordre', 1)
            ->assertJsonPath('data.plan.arrets.0.employes.0.action', 'PRISE_EN_CHARGE')
            ->assertJsonPath('data.plan.arrets.1.objets.0.action', 'DEPOSE')
            ->assertJsonPath('data.preparations.1.plan.occupation_debut_prevue', '2026-11-12T07:30:00+01:00');

        $this->assertNull($response->json('data.statut_execution'));
        $this->assertSame(Demande::STATUT_EN_ATTENTE, $demande->fresh()->statut);
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 1, 'demande_ids' => [$demande->id]], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.plan.arrets.0.ordre', 1)->assertJsonPath('data.lock_version', 1);
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 1, 'demande_ids' => [$demande->id], 'plan' => null], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.plan', null)->assertJsonPath('data.preparations.1.plan.arrets.0.ordre', 1)->assertJsonPath('data.lock_version', 2);
    }

    public function test_manual_plan_rejects_bad_schedule_order_and_items_outside_selected_demandes(): void
    {
        $user = $this->userWith(['create_course', 'update_course']);
        $selectedDemande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $otherDemande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $otherEmployee = DemandeEmploye::factory()->create(['demande_id' => $otherDemande->id]);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $badSchedule = ['arrets' => [
            ['ordre' => 1, 'lieu' => 'Départ', 'heure_prevue' => '2026-11-12T09:00:00+01:00'],
            ['ordre' => 2, 'lieu' => 'Arrivée', 'heure_prevue' => '2026-11-12T08:00:00+01:00'],
        ]];
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [$selectedDemande->id], 'plan' => $badSchedule], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $outsideReference = ['arrets' => [
            ['ordre' => 1, 'lieu' => 'Départ', 'employes' => [['demande_employe_id' => $otherEmployee->id, 'action' => 'PRISE_EN_CHARGE']]],
        ]];
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [$selectedDemande->id], 'plan' => $outsideReference], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNotFound()->assertJsonPath('code', 'COURSE_PLAN_REFERENCE_NOT_FOUND');
        $this->assertSame(1, DB::table('course_preparations')->where('course_id', $course['id'])->count());
    }

    public function test_candidate_selection_idempotency_replays_same_intent_and_rejects_changed_resources(): void
    {
        $user = $this->userWith(['create_course', 'update_course']);
        $chauffeurUser = User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
        $chauffeur = Chauffeur::factory()->create(['user_id' => $chauffeurUser->id, 'statut' => 'ACTIF']);
        $vehicule = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $otherVehicule = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $key = (string) Str::uuid();
        $payload = [
            'version_attendue' => 0,
            'demande_ids' => [],
            'chauffeur_id' => $chauffeur->id,
            'vehicule_id' => $vehicule->id,
        ];

        $first = $this->putJson('/api/courses/'.$course['id'], $payload, ['Idempotency-Key' => $key])->assertOk();
        $replay = $this->putJson('/api/courses/'.$course['id'], $payload, ['Idempotency-Key' => $key])->assertOk();
        $replay->assertJson($first->json());
        $this->assertSame(2, DB::table('course_preparations')->where('course_id', $course['id'])->count());
        $this->assertSame(2, DB::table('course_events')->where('course_id', $course['id'])->count());

        $payload['vehicule_id'] = $otherVehicule->id;
        $this->putJson('/api/courses/'.$course['id'], $payload, ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(2, DB::table('course_preparations')->where('course_id', $course['id'])->count());
        $this->assertSame(2, DB::table('course_events')->where('course_id', $course['id'])->count());
    }

    public function test_candidate_resource_references_return_not_found_for_unknown_profiles(): void
    {
        $user = $this->userWith(['create_course', 'update_course']);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [], 'chauffeur_id' => 999999999], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNotFound()->assertJsonPath('code', 'CHAUFFEUR_NOT_FOUND');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [], 'vehicule_id' => 999999999], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNotFound()->assertJsonPath('code', 'VEHICULE_NOT_FOUND');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [], 'chauffeur_id' => 0], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_inactive_vehicle_or_chauffeur_profiles_cannot_be_selected(): void
    {
        $user = $this->userWith(['create_course', 'update_course']);
        $activeUser = User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
        $inactiveChauffeur = Chauffeur::factory()->create(['user_id' => $activeUser->id, 'statut' => 'INACTIF']);
        $inactiveVehicle = Vehicule::factory()->create(['statut' => 'INACTIF']);
        $inactiveAccount = User::factory()->create(['statut' => 'INACTIF', 'must_change_password' => false]);
        $chauffeurWithInactiveAccount = Chauffeur::factory()->create(['user_id' => $inactiveAccount->id, 'statut' => 'ACTIF']);
        $this->authenticate($user);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [], 'chauffeur_id' => $inactiveChauffeur->id], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'RESOURCE_NOT_ELIGIBLE');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [], 'vehicule_id' => $inactiveVehicle->id], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'RESOURCE_NOT_ELIGIBLE');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [], 'chauffeur_id' => $chauffeurWithInactiveAccount->id], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'RESOURCE_NOT_ELIGIBLE');
        $this->assertSame(1, $course['version_preparation']);
        $this->assertSame(0, DB::table('course_events')->where('course_id', $course['id'])->where('action', 'preparation_updated')->count());
    }

    public function test_confirmed_course_can_only_be_started_and_finished_by_its_assigned_driver(): void
    {
        $dag = $this->userWith(['create_course', 'update_course', 'read_course', 'confirm_course']);
        $driverUser = $this->userWith(['start_course', 'finish_course']);
        $chauffeur = Chauffeur::factory()->create(['user_id' => $driverUser->id, 'statut' => 'ACTIF']);
        $vehicule = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $demande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $this->authenticate($dag);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $plan = [
            'occupation_debut_prevue' => '2026-11-12T07:30:00+01:00',
            'occupation_fin_prevue' => '2026-11-12T17:00:00+01:00',
            'chargement_confirme' => true,
            'arrets' => [['ordre' => 1, 'lieu' => 'Départ', 'employes' => [], 'objets' => []]],
        ];
        $this->putJson('/api/courses/'.$course['id'], [
            'version_attendue' => 0,
            'demande_ids' => [$demande->id],
            'chauffeur_id' => $chauffeur->id,
            'vehicule_id' => $vehicule->id,
            'plan' => $plan,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.lock_version', 1);

        $confirmKey = (string) Str::uuid();
        $confirmation = ['version_attendue' => 1, 'motif' => 'Organisation confirmée par la DAG.'];
        $confirmed = $this->postJson('/api/courses/'.$course['id'], $confirmation, ['Idempotency-Key' => $confirmKey])
            ->assertOk()->assertJsonPath('data.statut_execution', 'A_VENIR')->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.demarree_a', null)->assertJsonPath('data.chauffeur_libere_a', null);
        $this->assertSame(Demande::STATUT_VALIDEE, $demande->fresh()->statut);
        $this->assertDatabaseHas('demande_historiques', ['demande_id' => $demande->id, 'ancien_statut' => 'EN_ATTENTE', 'nouveau_statut' => 'VALIDEE', 'commentaire' => $confirmation['motif']]);
        $this->postJson('/api/courses/'.$course['id'], $confirmation, ['Idempotency-Key' => $confirmKey])->assertOk()->assertJson($confirmed->json());
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 2, 'demande_ids' => [$demande->id]], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'COURSE_STATE_CONFLICT');

        $otherDriver = $this->userWith(['start_course']);
        $this->authenticate($otherDriver);
        $otherDriverStart = $this->postJson('/api/courses/'.$course['id'].'/depart', ['version_attendue' => 2], ['Idempotency-Key' => (string) Str::uuid()]);
        $otherDriverStart->assertJsonPath('code', 'COURSE_NOT_FOUND')->assertNotFound();

        $this->authenticate($driverUser);
        $this->travelTo('2026-11-12 08:00:00 UTC');
        $startKey = (string) Str::uuid();
        $started = $this->postJson('/api/courses/'.$course['id'].'/depart', ['version_attendue' => 2], ['Idempotency-Key' => $startKey])
            ->assertOk()->assertJsonPath('data.statut_execution', 'EN_COURS')->assertJsonPath('data.lock_version', 3);
        $this->assertNotNull($started->json('data.demarree_a'));
        $this->assertSame(Demande::STATUT_VALIDEE, $demande->fresh()->statut);

        $this->travelTo('2026-11-12 10:00:00 UTC');
        $finishPayload = [
            'version_attendue' => 3,
            'fin_reelle' => '2026-11-12T09:00:00+00:00',
            'chauffeur_libere_a' => '2026-11-12T09:45:00+00:00',
            'vehicule_libere_a' => '2026-11-12T09:55:00+00:00',
        ];
        $finishKey = (string) Str::uuid();
        $finished = $this->postJson('/api/courses/'.$course['id'].'/terminer', $finishPayload, ['Idempotency-Key' => $finishKey])
            ->assertOk()->assertJsonPath('data.statut_execution', 'TERMINEE')->assertJsonPath('data.lock_version', 4);
        $this->assertSame('2026-11-12T09:00:00.000000Z', $finished->json('data.terminee_a'));
        $this->assertSame('2026-11-12T09:45:00.000000Z', $finished->json('data.chauffeur_libere_a'));
        $this->assertSame('2026-11-12T09:55:00.000000Z', $finished->json('data.vehicule_libere_a'));
        $this->assertSame(Demande::STATUT_VALIDEE, $demande->fresh()->statut);
        $this->postJson('/api/courses/'.$course['id'].'/terminer', $finishPayload, ['Idempotency-Key' => $finishKey])->assertOk()->assertJson($finished->json());
        $this->assertSame(1, DB::table('course_events')->where('course_id', $course['id'])->where('action', 'finished')->count());
        $this->travelBack();
    }

    public function test_course_confirmation_requires_manual_ready_plan_and_rejects_resource_overlap_atomically(): void
    {
        $dag = $this->userWith(['create_course', 'update_course', 'confirm_course']);
        $driverOne = $this->userWith([]);
        $driverTwo = $this->userWith([]);
        $chauffeurOne = Chauffeur::factory()->create(['user_id' => $driverOne->id, 'statut' => 'ACTIF']);
        $chauffeurTwo = Chauffeur::factory()->create(['user_id' => $driverTwo->id, 'statut' => 'ACTIF']);
        $sharedVehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $this->authenticate($dag);

        $firstDemande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $first = $this->createReadyCourse($firstDemande, $chauffeurOne, $sharedVehicle);
        $this->postJson('/api/courses/'.$first['id'], ['version_attendue' => 1, 'motif' => 'Plan validé.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.statut_execution', 'A_VENIR');

        $secondDemande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $second = $this->createReadyCourse($secondDemande, $chauffeurTwo, $sharedVehicle);
        $this->postJson('/api/courses/'.$second['id'], ['version_attendue' => 1, 'motif' => 'Plan validé.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'RESOURCE_CONFLICT');
        $this->assertNull(Course::query()->findOrFail($second['id'])->statut_execution);
        $this->assertSame(Demande::STATUT_EN_ATTENTE, $secondDemande->fresh()->statut);
        $this->assertSame(0, DB::table('course_events')->where('course_id', $second['id'])->where('action', 'confirmed')->count());
    }

    public function test_course_actions_require_dedicated_permissions_and_finish_times_cannot_precede_start_or_be_future(): void
    {
        $dag = $this->userWith(['create_course', 'update_course']);
        $driver = $this->userWith(['start_course', 'finish_course']);
        $chauffeur = Chauffeur::factory()->create(['user_id' => $driver->id, 'statut' => 'ACTIF']);
        $vehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $demande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $this->authenticate($dag);
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->putJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'demande_ids' => [$demande->id], 'chauffeur_id' => $chauffeur->id, 'vehicule_id' => $vehicle->id, 'plan' => [
            'occupation_debut_prevue' => '2026-11-12T07:30:00+01:00', 'occupation_fin_prevue' => '2026-11-12T17:00:00+01:00', 'chargement_confirme' => true,
            'arrets' => [['ordre' => 1, 'lieu' => 'Départ', 'employes' => [], 'objets' => []]],
        ]], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->authenticate($dag);
        $this->postJson('/api/courses/'.$course['id'], ['version_attendue' => 1, 'motif' => 'Autorisé.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $dag->givePermissionTo('confirm_course');
        $this->postJson('/api/courses/'.$course['id'], ['version_attendue' => 1, 'motif' => 'Autorisé.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->authenticate($driver);
        $this->travelTo('2026-11-12 08:00:00 UTC');
        $driverStart = $this->postJson('/api/courses/'.$course['id'].'/depart', ['version_attendue' => 2], ['Idempotency-Key' => (string) Str::uuid()]);
        $driverStart->assertJsonPath('data.statut_execution', 'EN_COURS')->assertOk();
        $this->postJson('/api/courses/'.$course['id'].'/terminer', [
            'version_attendue' => 3,
            'fin_reelle' => '2026-11-12T07:59:00+00:00',
            'chauffeur_libere_a' => '2026-11-12T08:01:00+00:00',
            'vehicule_libere_a' => '2026-11-12T08:02:00+00:00',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertSame('EN_COURS', Course::query()->findOrFail($course['id'])->statut_execution);
        $this->travelBack();
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
        $this->withToken($user->createToken('treatment-tests')->plainTextToken);
    }
}
