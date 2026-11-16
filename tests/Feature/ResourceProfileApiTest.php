<?php

namespace Tests\Feature;

use App\Models\Chauffeur;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicule;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ResourceProfileApiTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Les tests des profils de ressources exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Le serveur n’a pas sélectionné aninf_test.');
        }

        return $app;
    }

    public function test_vehicle_profile_crud_is_versioned_idempotent_audited_and_never_deleted(): void
    {
        $this->admin();
        $payload = ['immatriculation' => '  test-resource-001 ', 'places_utiles' => 6];
        $key = (string) Str::uuid();

        $created = $this->command('POST', '/api/parametrage/vehicules', $payload, $key)
            ->assertCreated()
            ->assertJsonPath('data.immatriculation', 'TEST-RESOURCE-001')
            ->assertJsonPath('data.places_utiles', 6)
            ->assertJsonPath('data.statut', 'ACTIF')
            ->assertJsonPath('data.lock_version', 0);
        $id = $created->json('data.id');
        $this->assertSame(['id', 'immatriculation', 'places_utiles', 'statut', 'lock_version'], array_keys($created->json('data')));
        $this->assertDatabaseHas('vehicules', ['id' => $id, 'immatriculation' => 'TEST-RESOURCE-001', 'places_utiles' => 6]);

        $this->getJson('/api/parametrage/vehicules/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/parametrage/vehicules?search=RESOURCE-001&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);

        $updated = $this->command('PUT', '/api/parametrage/vehicules/'.$id, [
            'places_utiles' => 8,
            'version_attendue' => 0,
            'motif' => 'Correction de test',
        ])->assertOk()->assertJsonPath('data.places_utiles', 8)->assertJsonPath('data.lock_version', 1);
        $deactivated = $this->command('PUT', '/api/parametrage/vehicules/'.$id.'/deactivate', [
            'version_attendue' => $updated->json('data.lock_version'),
            'motif' => 'Désactivation de test',
        ])->assertOk()->assertJsonPath('data.statut', 'INACTIF')->assertJsonPath('data.lock_version', 2);
        $this->command('PUT', '/api/parametrage/vehicules/'.$id.'/activate', [
            'version_attendue' => $deactivated->json('data.lock_version'),
            'motif' => 'Réactivation de test',
        ])->assertOk()->assertJsonPath('data.statut', 'ACTIF')->assertJsonPath('data.lock_version', 3);

        $this->assertSame(4, DB::table('organisation_audits')->where('object_type', 'vehicule')->where('object_id', $id)->count());
        $audit = $this->getJson('/api/parametrage/audit?object_type=vehicule&object_id='.$id)
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->json('data.0');
        $this->assertSame('vehicule', $audit['object_type']);
        $this->assertSame('ACTIF', $audit['after_values']->statut ?? $audit['after_values']['statut'] ?? null);
        $this->deleteJson('/api/parametrage/vehicules/'.$id)->assertMethodNotAllowed();
        $this->assertDatabaseHas('vehicules', ['id' => $id, 'statut' => 'ACTIF']);
    }

    public function test_vehicle_profile_rejects_invalid_values_duplicate_plates_and_stale_versions(): void
    {
        $this->admin();
        $this->command('POST', '/api/parametrage/vehicules', ['immatriculation' => '', 'places_utiles' => 0])->assertUnprocessable()->assertJsonValidationErrors(['immatriculation', 'places_utiles']);
        $created = $this->command('POST', '/api/parametrage/vehicules', ['immatriculation' => 'TEST-DUPLICATE-1', 'places_utiles' => 2])->assertCreated();
        $id = $created->json('data.id');
        $this->command('POST', '/api/parametrage/vehicules', ['immatriculation' => 'test-duplicate-1', 'places_utiles' => 4])->assertConflict()->assertJsonPath('code', 'RESOURCE_CONFLICT');

        $this->command('PUT', '/api/parametrage/vehicules/'.$id, ['places_utiles' => 5, 'version_attendue' => 5])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertDatabaseHas('vehicules', ['id' => $id, 'places_utiles' => 2, 'lock_version' => 0]);
        $this->assertSame(1, DB::table('organisation_audits')->where('object_type', 'vehicule')->where('object_id', $id)->count());
    }

    public function test_idempotency_replays_one_vehicle_creation_and_rejects_a_different_intent(): void
    {
        $this->admin();
        $key = (string) Str::uuid();
        $payload = ['immatriculation' => 'TEST-IDEMPOTENT-1', 'places_utiles' => 4];

        $first = $this->command('POST', '/api/parametrage/vehicules', $payload, $key)->assertCreated();
        $replay = $this->command('POST', '/api/parametrage/vehicules', $payload, $key)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Vehicule::query()->where('immatriculation', 'TEST-IDEMPOTENT-1')->count());
        $this->assertSame(1, DB::table('organisation_audits')->where('object_type', 'vehicule')->where('object_id', $first->json('data.id'))->count());

        $this->command('POST', '/api/parametrage/vehicules', ['immatriculation' => 'TEST-IDEMPOTENT-2', 'places_utiles' => 4], $key)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, DB::table('organisation_commands')->where('command_key', $key)->count());
    }

    public function test_chauffeur_profile_uses_one_user_link_and_exposes_only_the_name_projection(): void
    {
        $this->admin();
        $user = User::factory()->create(['nom' => 'Test Chauffeur', 'prenom' => 'Awa']);
        $created = $this->command('POST', '/api/parametrage/chauffeurs', ['user_id' => $user->id])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.utilisateur.nom', 'Test Chauffeur')
            ->assertJsonPath('data.utilisateur.prenom', 'Awa');
        $id = $created->json('data.id');
        $this->assertSame(['id', 'user_id', 'utilisateur', 'statut', 'lock_version'], array_keys($created->json('data')));
        $this->assertSame(['id', 'nom', 'prenom', 'statut'], array_keys($created->json('data.utilisateur')));
        $this->assertTrue($user->fresh()->chauffeurProfile->is(Chauffeur::findOrFail($id)));

        $this->command('POST', '/api/parametrage/chauffeurs', ['user_id' => $user->id])->assertConflict()->assertJsonPath('code', 'RESOURCE_CONFLICT');
        $missingUserId = (int) User::query()->max('id') + 100;
        $this->command('POST', '/api/parametrage/chauffeurs', ['user_id' => $missingUserId])->assertNotFound()->assertJsonPath('code', 'USER_NOT_FOUND');

        $unlinked = $this->command('PUT', '/api/parametrage/chauffeurs/'.$id, ['user_id' => null, 'version_attendue' => 0, 'motif' => 'Retrait du compte de test'])
            ->assertOk()->assertJsonPath('data.user_id', null)->assertJsonPath('data.utilisateur', null)->assertJsonPath('data.lock_version', 1);
        $this->assertDatabaseHas('chauffeurs', ['id' => $id, 'user_id' => null]);
        $this->assertSame(2, DB::table('organisation_audits')->where('object_type', 'chauffeur')->where('object_id', $id)->count());
        $this->assertSame(1, $unlinked->json('data.lock_version'));
    }

    public function test_chauffeur_filter_and_per_page_limit_are_applied(): void
    {
        $this->admin();
        $user = User::factory()->create(['nom' => 'Recherche Chauffeur', 'prenom' => 'Nadia']);
        Chauffeur::factory()->create(['user_id' => $user->id]);
        $otherUser = User::factory()->create(['nom' => 'Autre Chauffeur', 'prenom' => 'Paul']);
        Chauffeur::factory()->create(['user_id' => $otherUser->id]);
        Chauffeur::factory()->create(['user_id' => null, 'statut' => 'INACTIF']);

        $this->getJson('/api/parametrage/chauffeurs?search=Nadia&statut=ACTIF')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.utilisateur.prenom', 'Nadia');
        $this->getJson('/api/parametrage/chauffeurs?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_profile_routes_require_authentication(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $this->getJson('/api/parametrage/vehicules')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_profile_routes_reject_authenticated_users_without_permission(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create();
        $this->withToken($user->createToken('without-permission')->plainTextToken)
            ->getJson('/api/parametrage/vehicules')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_active_profile_permission_allows_access(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $permission = Permission::query()->where('name', 'read_vehicle')->firstOrFail();
        $role = Role::factory()->create(['statut' => 'ACTIF']);
        $role->givePermissionTo($permission);
        $reader = User::factory()->create();
        $reader->assignRole($role);

        $this->withToken($reader->createToken('active-resource-reader')->plainTextToken)
            ->getJson('/api/parametrage/vehicules')->assertOk();
    }

    public function test_profile_routes_reject_permissions_from_inactive_roles(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $permission = Permission::query()->where('name', 'read_vehicle')->firstOrFail();
        $role = Role::factory()->create(['statut' => 'INACTIF']);
        $role->givePermissionTo($permission);
        $reader = User::factory()->create();
        $reader->assignRole($role);

        $this->withToken($reader->createToken('inactive-resource-reader')->plainTextToken)
            ->getJson('/api/parametrage/vehicules')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_profile_routes_require_password_change_before_access(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $permission = Permission::query()->where('name', 'read_vehicle')->firstOrFail();
        $reader = User::factory()->create(['must_change_password' => true]);
        $reader->givePermissionTo($permission);

        $this->withToken($reader->createToken('password-change-required')->plainTextToken)
            ->getJson('/api/parametrage/vehicules')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_contract_lists_all_profile_routes_with_matching_permissions_and_write_controls(): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! isset($route->defaults['resource_profile_kind'])) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $path = '/'.$route->uri();
                $operation = $contract['paths'][$path][strtolower($method)];
                $permission = $route->defaults['resource_profile_permission'];
                $this->assertContains($permission, $operation['x-permissions']);
                $this->assertSame('implemented', $operation['x-implementation-status']);
                $this->assertArrayHasKey('401', $operation['responses']);
                $this->assertArrayHasKey('403', $operation['responses']);
                if ($method !== 'GET') {
                    $this->assertArrayHasKey('409', $operation['responses']);
                    $this->assertArrayHasKey('422', $operation['responses']);
                    $parameterNames = array_map(static function (array $parameter) use ($contract): string {
                        if (isset($parameter['$ref'])) {
                            $name = substr($parameter['$ref'], strlen('#/components/parameters/'));

                            return $contract['components']['parameters'][$name]['name'];
                        }

                        return $parameter['name'];
                    }, $operation['parameters']);
                    $this->assertContains('Idempotency-Key', $parameterNames);
                }
                $actual[] = strtolower($method).' '.$path;
            }
        }

        sort($actual);
        $this->assertCount(12, $actual);
        $this->assertContains('get /api/parametrage/vehicules', $actual);
        $this->assertContains('put /api/parametrage/chauffeurs/{id}/deactivate', $actual);
    }

    public function test_seeders_grant_profile_permissions_only_to_the_system_administrator(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $admin = Role::query()->where('system_key', 'super_admin')->firstOrFail();
        $demandeur = Role::query()->where('system_key', 'demandeur')->firstOrFail();

        $this->assertTrue($admin->hasPermissionTo('read_vehicle'));
        $this->assertTrue($admin->hasPermissionTo('create_driver'));
        $this->assertFalse($demandeur->hasPermissionTo('read_vehicle'));
        $this->assertFalse($demandeur->hasPermissionTo('read_driver'));
    }

    private function admin(): User
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create(['password' => Hash::make('ResourceAdmin123!')]);
        $user->assignRole(Role::query()->where('system_key', 'super_admin')->firstOrFail());
        $this->withToken($user->createToken('resource-profile-test')->plainTextToken);

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function command(string $method, string $uri, array $payload = [], ?string $key = null): TestResponse
    {
        return $this->json($method, $uri, $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
