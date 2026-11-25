<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\TypeSortie;
use App\Models\User;
use Database\Seeders\OfficialOrganisationSeeder;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemandeServiceApiTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (
            config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'aninf_test'
            || filled(config('database.connections.mysql.url'))
            || DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test'
        ) {
            throw new \RuntimeException('Les tests Demande/Service exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }

        return $app;
    }

    public function test_demande_can_be_created_and_submitted_with_only_a_direction(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande', 'submit_demande']);
        $direction = $this->generalDirection();

        $created = $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($direction))
            ->assertCreated()
            ->assertJsonPath('data.direction.id', $direction->id)
            ->assertJsonStructure(['data' => ['service', 'rattachement_direction']])
            ->assertJsonPath('data.service', null)
            ->assertJsonPath('data.rattachement_direction', null);

        $this->postJson('/api/demandes/'.$created->json('data.id').'/soumission')
            ->assertOk()
            ->assertJsonPath('data.statut', Demande::STATUT_EN_ATTENTE);
        $this->assertDatabaseHas('demandes', [
            'id' => $created->json('data.id'),
            'direction_id' => $direction->id,
            'service_id' => null,
            'rattachement_direction_id' => null,
        ]);
    }

    public function test_direction_catalogue_exposes_five_stable_general_direction_choices_separately_from_services(): void
    {
        $direction = $this->generalDirection();
        $expected = [
            'CONSEILLERS' => 'Conseillers',
            'CHARGES_ETUDE' => 'Chargés d’Étude',
            'COORDONNATEUR' => 'Coordonnateur',
            'CABINET' => 'Cabinet',
            'AUTRE' => 'Autre',
        ];
        $ids = $direction->rattachements()->orderBy('code')->pluck('id', 'code')->all();
        $this->seed(OfficialOrganisationSeeder::class);
        $this->assertSame($ids, $direction->rattachements()->orderBy('code')->pluck('id', 'code')->all());

        ['token' => $token] = $this->userWithPermissions(['read_direction', 'read_service']);
        $catalogue = $this->withToken($token)->getJson('/api/directions?search='.urlencode($direction->nom))
            ->assertOk();
        $choices = collect($catalogue->json('data'))->firstWhere('id', $direction->id)['rattachements'];
        $this->assertCount(5, $choices);
        $this->assertEquals($expected, array_column($choices, 'nom', 'code'));
        foreach ($choices as $choice) {
            $this->assertEqualsCanonicalizing(['id', 'code', 'nom'], array_keys($choice));
            $this->assertSame($ids[$choice['code']], $choice['id']);
        }

        $services = $this->getJson('/api/services?direction_id='.$direction->id.'&per_page=100')->assertOk();
        $this->assertSame([], array_intersect($expected, array_column($services->json('data'), 'nom')));
        $this->assertSame([], array_intersect(array_keys($expected), array_column($services->json('data'), 'code')));
        $other = Direction::factory()->create();
        $this->getJson('/api/directions?search='.urlencode($other->code))->assertOk()
            ->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.0.rattachements', []);
    }

    public function test_general_direction_choice_is_saved_displayed_and_copied_to_the_modification(): void
    {
        $direction = $this->generalDirection();
        $cabinet = $direction->rattachements()->where('code', 'CABINET')->firstOrFail();
        $autre = $direction->rattachements()->where('code', 'AUTRE')->firstOrFail();
        ['token' => $token] = $this->userWithPermissions(['create_demande', 'read_demande', 'update_demande', 'submit_demande']);
        $payload = $this->validPayload($direction, ['rattachement_direction_id' => $cabinet->id]);

        $created = $this->withToken($token)->postJson('/api/demandes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.rattachement_direction.id', $cabinet->id)
            ->assertJsonPath('data.rattachement_direction.code', 'CABINET')
            ->assertJsonPath('data.rattachement_direction.nom', 'Cabinet')
            ->assertJsonPath('data.service', null);
        $id = $created->json('data.id');
        $uri = '/api/demandes/'.$id;
        $this->getJson($uri)->assertOk()->assertJsonPath('data.rattachement_direction.id', $cabinet->id);
        $this->putJson($uri, [...$payload, 'rattachement_direction_id' => null])
            ->assertOk()->assertJsonPath('data.rattachement_direction', null);
        $this->putJson($uri, $payload)->assertOk()->assertJsonPath('data.rattachement_direction.id', $cabinet->id);
        $this->postJson($uri.'/soumission')->assertOk()->assertJsonPath('data.statut', Demande::STATUT_EN_ATTENTE);

        Demande::query()->findOrFail($id)->update(['statut' => Demande::STATUT_VALIDEE]);
        $this->postJson($uri.'/modification')->assertOk();
        $this->assertDatabaseHas('demande_modifications', ['demande_id' => $id, 'rattachement_direction_id' => $cabinet->id]);
        $this->putJson($uri.'/modification', [...$payload, 'rattachement_direction_id' => $autre->id])->assertOk();
        $this->postJson($uri.'/modification/soumission')->assertOk()
            ->assertJsonPath('data.statut', Demande::STATUT_REVALIDATION_REQUISE)
            ->assertJsonPath('data.rattachement_direction.id', $cabinet->id);
        $this->assertDatabaseHas('demande_modifications', ['demande_id' => $id, 'rattachement_direction_id' => $autre->id, 'service_id' => null]);
    }

    public function test_demande_rejects_an_unknown_or_mismatched_direction_choice_and_a_simultaneous_service(): void
    {
        $direction = $this->generalDirection();
        $cabinet = $direction->rattachements()->where('code', 'CABINET')->firstOrFail();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $other = Direction::factory()->create();
        $unknownId = DB::table('rattachements_direction')->max('id') + 1;
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $this->withToken($token);
        $payload = $this->validPayload($direction, ['rattachement_direction_id' => $cabinet->id]);
        $initialCount = Demande::query()->count();
        foreach ([
            ['rattachement_direction_id' => $unknownId],
            ['direction_id' => $other->id],
            ['service_id' => $service->id],
        ] as $invalid) {
            $this->postJson('/api/demandes', [...$payload, ...$invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('rattachement_direction_id');
        }
        $this->assertSame($initialCount, Demande::query()->count());
    }

    public function test_demande_can_be_created_with_an_active_service_from_its_direction(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($direction, ['service_id' => $service->id]))
            ->assertCreated()
            ->assertJsonPath('data.direction.id', $direction->id)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonPath('data.service.nom', $service->nom)
            ->assertJsonPath('data.service.code', $service->code);

        $this->assertDatabaseHas('demandes', [
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_demande_rejects_a_service_from_another_direction(): void
    {
        $initialDemandesCount = Demande::query()->count();
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $direction = Direction::factory()->create();
        $service = Service::factory()->create();

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($direction, ['service_id' => $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->assertSame($initialDemandesCount, Demande::query()->count());
    }

    public function test_demande_rejects_an_inactive_service_and_an_inactive_direction(): void
    {
        $initialDemandesCount = Demande::query()->count();
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id, 'statut' => 'INACTIF']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($direction, ['service_id' => $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $inactiveDirection = Direction::factory()->create(['statut' => 'INACTIF']);
        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($inactiveDirection))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction_id');

        $this->assertSame($initialDemandesCount, Demande::query()->count());
    }

    public function test_draft_update_persists_an_optional_service(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande', 'update_demande']);
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $created = $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($direction))
            ->assertCreated();

        $this->withToken($token)
            ->putJson('/api/demandes/'.$created->json('data.id'), $this->validPayload($direction, ['service_id' => $service->id]))
            ->assertOk()
            ->assertJsonPath('data.service.id', $service->id);

        $this->assertDatabaseHas('demandes', [
            'id' => $created->json('data.id'),
            'service_id' => $service->id,
        ]);
    }

    public function test_modification_snapshot_copies_and_updates_the_optional_service(): void
    {
        ['token' => $token, 'user' => $user] = $this->userWithPermissions(['update_demande']);
        $direction = Direction::factory()->create();
        $initialService = Service::factory()->create(['direction_id' => $direction->id]);
        $newService = Service::factory()->create(['direction_id' => $direction->id]);
        $demande = Demande::factory()
            ->for($user, 'demandeur')
            ->for($direction)
            ->create(['service_id' => $initialService->id, 'statut' => Demande::STATUT_VALIDEE]);
        $demande->destinations()->create([
            'entite_geographique_id' => EntiteGeographique::factory()->create()->id,
            'ordre' => 1,
        ]);
        $demande->employes()->create(['nom' => 'Agent', 'prenom' => 'Test']);

        $this->withToken($token)->postJson('/api/demandes/'.$demande->id.'/modification')->assertOk();

        $this->assertDatabaseHas('demande_modifications', [
            'demande_id' => $demande->id,
            'service_id' => $initialService->id,
        ]);

        $this->withToken($token)
            ->putJson('/api/demandes/'.$demande->id.'/modification', $this->validPayload($direction, ['service_id' => $newService->id]))
            ->assertOk();

        $this->assertDatabaseHas('demande_modifications', [
            'demande_id' => $demande->id,
            'service_id' => $newService->id,
        ]);
    }

    public function test_demandeur_can_list_only_active_services_of_the_selected_direction(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $demandeurRole = Role::query()->where('system_key', 'demandeur')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole($demandeurRole);
        $token = $user->createToken('test-token')->plainTextToken;
        $direction = Direction::factory()->create();
        $otherDirection = Direction::factory()->create();
        $expectedService = Service::factory()->create(['direction_id' => $direction->id]);
        Service::factory()->create(['direction_id' => $direction->id, 'statut' => 'INACTIF']);
        Service::factory()->create(['direction_id' => $otherDirection->id]);

        $this->withToken($token)
            ->getJson('/api/services?direction_id='.$direction->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expectedService->id);

        $this->withToken($token)
            ->getJson('/api/services')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction_id');

        $inactiveDirection = Direction::factory()->create(['statut' => 'INACTIF']);
        $this->withToken($token)
            ->getJson('/api/services?direction_id='.$inactiveDirection->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction_id');
    }

    public function test_user_without_read_service_cannot_read_the_service_catalogue(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;
        $direction = Direction::factory()->create();

        $this->withToken($token)
            ->getJson('/api/services?direction_id='.$direction->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    private function generalDirection(): Direction
    {
        $this->seed(OfficialOrganisationSeeder::class);

        return Direction::query()->where('code', 'DG')->firstOrFail();
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array{token: string, user: User}
     */
    private function userWithPermissions(array $permissionNames): array
    {
        $user = User::factory()->create();

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web'],
                ['libelle' => $permissionName],
            );
            $user->givePermissionTo($permission);
        }

        return [
            'token' => $user->createToken('test-token')->plainTextToken,
            'user' => $user,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(Direction $direction, array $overrides = []): array
    {
        return [
            'direction_id' => $direction->id,
            'type_sortie_id' => TypeSortie::factory()->create()->id,
            'adresse_depart_id' => EntiteGeographique::factory()->create()->id,
            'date_depart' => '2026-11-05',
            'heure_depart' => '08:00:00',
            'motif' => 'Mission de service',
            'destinations' => [
                ['entite_geographique_id' => EntiteGeographique::factory()->create()->id, 'ordre' => 1],
            ],
            'employes' => [
                ['nom' => 'AGENT', 'prenom' => 'Test'],
            ],
            'objets' => [],
            ...$overrides,
        ];
    }
}
