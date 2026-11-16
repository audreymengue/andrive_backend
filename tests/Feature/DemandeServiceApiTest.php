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

    public function test_demande_can_be_created_with_only_a_direction(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $direction = Direction::factory()->create();

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload($direction))
            ->assertCreated()
            ->assertJsonPath('data.direction.id', $direction->id)
            ->assertJsonPath('data.service', null);

        $this->assertDatabaseHas('demandes', [
            'direction_id' => $direction->id,
            'service_id' => null,
        ]);
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
