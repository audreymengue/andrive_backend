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
use Database\Seeders\OrganisationPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OrganisationApiTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Les tests organisation exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Le serveur n’a pas sélectionné aninf_test.');
        }

        return $app;
    }

    public static function endpoints(): array
    {
        return [
            ['get', '/api/directions'], ['get', '/api/parametrage/directions'], ['post', '/api/directions'], ['get', '/api/directions/1'], ['put', '/api/directions/1'], ['put', '/api/directions/1/activate'], ['put', '/api/directions/1/deactivate'],
            ['get', '/api/parametrage/services'], ['get', '/api/services'], ['post', '/api/services'], ['get', '/api/services/1'], ['put', '/api/services/1'], ['put', '/api/services/1/activate'], ['put', '/api/services/1/deactivate'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_route_requires_authentication(string $method, string $uri): void
    {
        $response = $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertContractResponse($method, str_replace('/1', '/{id}', $uri), $response);
    }

    public function test_regular_user_with_all_permissions_is_not_an_administrator(): void
    {
        $this->seed(OrganisationPermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::query()->whereIn('name', ['read_direction', 'read_service', 'create_direction', 'create_service'])->get());
        $this->withToken($user->createToken('test')->plainTextToken);
        $this->getJson('/api/directions')->assertOk();
        $this->getJson('/api/parametrage/directions')->assertForbidden();
        $this->getJson('/api/parametrage/services')->assertForbidden();
        $this->postJson('/api/directions', [])->assertForbidden();
        $this->postJson('/api/services', [])->assertForbidden();
    }

    public function test_administrator_still_needs_the_permission(): void
    {
        $this->admin();
        Role::query()->where('system_key', 'super_admin')->firstOrFail()->revokePermissionTo('create_direction');
        $this->postJson('/api/directions', [])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_user_without_permission_cannot_read_the_operational_catalogue(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/directions')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_all_organisation_routes_and_response_fields_match_the_local_contract(): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if ($route->defaults['organisation_kind'] ?? false) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = strtolower($method).' /'.$route->uri();
                }
            }
        }
        sort($actual);
        $expected = [];
        foreach (self::endpoints() as [$method, $path]) {
            $expected[] = $method.' '.str_replace('/1', '/{id}', $path);
        }
        sort($expected);
        $this->assertSame($expected, $actual);
        foreach ($actual as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            $this->assertContains($contract['paths'][$path][$method]['x-implementation-status'], ['implemented', 'partial']);
        }
        $this->admin();
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        foreach ([['directions', $direction->id, 'ParamDirection'], ['services', $service->id, 'ParamService']] as [$path, $id, $schema]) {
            $response = $this->getJson('/api/'.$path.'/'.$id)->assertOk();
            $this->assertSame(array_keys($contract['components']['schemas'][$schema]['properties']), array_keys($response->json('data')));
        }
    }

    public function test_disabled_accounts_password_obligation_and_inactive_roles_are_rejected(): void
    {
        $user = $this->admin();
        $user->update(['must_change_password' => true]);
        $this->getJson('/api/parametrage/directions')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->update(['must_change_password' => false, 'statut' => 'INACTIF']);
        $this->getJson('/api/parametrage/directions')->assertForbidden();
        $user->update(['statut' => 'ACTIF']);
        Role::query()->where('system_key', 'super_admin')->update(['statut' => 'INACTIF']);
        $this->getJson('/api/parametrage/directions')->assertForbidden();
    }

    public function test_role_identity_cannot_be_impersonated_by_reusing_its_name(): void
    {
        $this->seed(OrganisationPermissionSeeder::class);
        Role::query()->where('system_key', 'super_admin')->update(['name' => 'renamed_'.Str::random(8)]);
        $role = Role::factory()->create(['name' => 'super_admin', 'statut' => 'ACTIF']);
        $role->givePermissionTo('read_direction');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/parametrage/directions')->assertForbidden();
    }

    public function test_direction_crud_status_versions_and_audit(): void
    {
        $this->admin();
        $code = 'ORG_'.Str::upper(Str::random(12));
        $created = $this->command('post', '/api/directions', ['nom' => 'Direction de test transactionnelle', 'code' => $code])->assertCreated()->assertJsonPath('data.lock_version', 0);
        $id = $created->json('data.id');
        $this->assertSame(['id', 'nom', 'code', 'description', 'statut', 'lock_version'], array_keys($created->json('data')));
        $this->getJson('/api/directions/'.$id)->assertOk()->assertJsonPath('data.code', $code);
        $this->command('put', '/api/directions/'.$id, ['nom' => 'Nom corrigé', 'version_attendue' => 0])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->command('put', '/api/directions/'.$id.'/deactivate', ['version_attendue' => 1, 'motif' => 'Désactivation de test'])->assertOk()->assertJsonPath('data.statut', 'INACTIF');
        $this->command('put', '/api/directions/'.$id.'/activate', ['version_attendue' => 2, 'motif' => 'Réactivation de test'])->assertOk()->assertJsonPath('data.statut', 'ACTIF');
        $this->assertSame(4, DB::table('organisation_audits')->where('object_type', 'direction')->where('object_id', $id)->count());
        $this->deleteJson('/api/directions/'.$id)->assertMethodNotAllowed();
        $this->assertDatabaseHas('directions', ['id' => $id, 'code' => $code]);
    }

    public function test_filters_pagination_and_operational_active_catalogue(): void
    {
        $this->admin();
        $prefix = 'ORG_SEARCH_'.Str::random(12);
        Direction::factory()->create(['nom' => $prefix.' A', 'statut' => 'ACTIF']);
        Direction::factory()->create(['nom' => $prefix.' B', 'statut' => 'INACTIF']);
        $this->getJson('/api/directions?search='.$prefix)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/parametrage/directions?search='.$prefix.'&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.statut', 'INACTIF');
        $this->getJson('/api/parametrage/directions?search='.$prefix.'&statut=INACTIF')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/parametrage/directions?per_page=100')->assertOk();
        foreach (['101', '0', 'abc'] as $value) {
            $this->getJson('/api/directions?per_page='.$value)->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }
    }

    public function test_service_relationship_creation_modification_and_filter(): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $other = Direction::factory()->create();
        $created = $this->command('post', '/api/services', ['direction_id' => $direction->id, 'nom' => 'Service de test', 'code' => 'ORG_'.Str::random(12)])->assertCreated()->assertJsonPath('data.direction_id', $direction->id);
        $id = $created->json('data.id');
        $this->assertSame(['id', 'direction_id', 'nom', 'code', 'description', 'statut', 'lock_version'], array_keys($created->json('data')));
        $this->assertTrue(Service::findOrFail($id)->direction->is($direction));
        $this->assertTrue($direction->services()->whereKey($id)->exists());
        Service::factory()->create(['direction_id' => $other->id]);
        $this->getJson('/api/parametrage/services?direction_id='.$direction->id)->assertOk()->assertJsonCount(1, 'data');
        $this->command('put', '/api/services/'.$id, ['description' => 'Description corrigée', 'version_attendue' => 0])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->command('put', '/api/services/'.$id, ['direction_id' => $other->id, 'version_attendue' => 1])->assertUnprocessable()->assertJsonValidationErrors('direction_id');
        $this->command('put', '/api/services/'.$id.'/deactivate', ['version_attendue' => 1, 'motif' => 'Test'])->assertOk()->assertJsonPath('data.statut', 'INACTIF');
        $this->command('put', '/api/services/'.$id.'/activate', ['version_attendue' => 2, 'motif' => 'Test'])->assertOk()->assertJsonPath('data.statut', 'ACTIF');
        $this->deleteJson('/api/services/'.$id)->assertMethodNotAllowed();
    }

    public function test_parent_deactivation_preserves_children_users_and_demandes(): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $user = User::factory()->create(['direction_id' => $direction->id]);
        $demande = Demande::factory()->create(['direction_id' => $direction->id, 'demandeur_id' => $user->id]);
        $this->command('put', '/api/directions/'.$direction->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Test de préservation'])->assertOk();
        $this->assertSame('ACTIF', $service->fresh()->statut);
        $this->assertSame('ACTIF', $user->fresh()->statut);
        $this->assertSame($demande->statut, $demande->fresh()->statut);
        $this->assertSame($direction->id, $demande->fresh()->direction_id);
        $this->command('post', '/api/services', ['direction_id' => $direction->id, 'nom' => 'Interdit', 'code' => 'ORG_'.Str::random(12)])->assertUnprocessable()->assertJsonValidationErrors('direction_id');
        $this->getJson('/api/directions?search='.urlencode($direction->code))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validation_immutable_fields_and_unknown_references(): void
    {
        $this->admin();
        $this->command('post', '/api/directions', [])->assertUnprocessable()->assertJsonValidationErrors(['nom', 'code']);
        $direction = Direction::factory()->create();
        $this->command('post', '/api/directions', ['nom' => 'Doublon', 'code' => mb_strtolower($direction->code)])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->command('put', '/api/directions/'.$direction->id, ['code' => 'CHANGED', 'version_attendue' => 0])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/directions', ['nom' => 'Sans clé', 'code' => 'ORG_'.Str::random(12)], ['Idempotency-Key' => 'invalid'])->assertUnprocessable();
        $this->getJson('/api/directions/999999999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->command('post', '/api/services', ['direction_id' => 999999999, 'nom' => 'Inexistant', 'code' => 'ORG_'.Str::random(12)])->assertNotFound();
        $this->command('post', '/api/services', ['direction_id' => $direction->id, 'nom' => 'Description trop longue', 'code' => 'ORG_'.Str::random(12), 'description' => str_repeat('x', 1001)])->assertUnprocessable();
    }

    public function test_idempotence_and_stale_version_have_no_duplicate_effect(): void
    {
        $this->admin();
        $key = (string) Str::uuid();
        $payload = ['nom' => 'Test répétition', 'code' => 'ORG_'.Str::random(12)];
        $a = $this->command('post', '/api/directions', $payload, $key)->assertCreated();
        $this->command('post', '/api/directions', $payload, $key)->assertCreated()->assertExactJson($a->json());
        $this->command('post', '/api/directions', [...$payload, 'nom' => 'Autre intention'], $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $id = $a->json('data.id');
        $this->command('put', '/api/directions/'.$id, ['nom' => 'Nouvelle version', 'version_attendue' => 0])->assertOk();
        $this->command('put', '/api/directions/'.$id, ['nom' => 'Écrasement interdit', 'version_attendue' => 0])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertSame('Nouvelle version', Direction::findOrFail($id)->nom);
        $this->assertSame(2, DB::table('organisation_audits')->where('object_type', 'direction')->where('object_id', $id)->count());
    }

    public function test_failure_to_write_audit_rolls_back_the_mutation(): void
    {
        $this->admin();
        $code = 'ORG_'.Str::random(12);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'insert into `organisation_audits`')) {
                throw new QueryException('mysql', $query, [], new \PDOException('Panne audit simulée'));
            }
        });
        $this->command('post', '/api/directions', ['nom' => 'Test atomique', 'code' => $code])->assertStatus(503)->assertJsonPath('code', 'TEMPORARILY_UNAVAILABLE');
        $this->assertDatabaseMissing('directions', ['code' => mb_strtoupper($code)]);
    }

    public function test_service_code_is_unique_only_within_its_direction(): void
    {
        $this->admin();
        $a = Direction::factory()->create();
        $b = Direction::factory()->create();
        $payload = ['direction_id' => $a->id, 'nom' => 'Service', 'code' => 'ORG_'.Str::random(12)];
        $this->command('post', '/api/services', $payload)->assertCreated();
        $this->command('post', '/api/services', $payload)->assertUnprocessable();
        $this->command('post', '/api/services', [...$payload, 'direction_id' => $b->id])->assertCreated();
    }

    public function test_existing_demande_create_update_submit_and_read_still_work(): void
    {
        $user = User::factory()->create();
        foreach (['create_demande', 'update_demande', 'submit_demande', 'read_demande'] as $name) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['libelle' => $name]));
        }
        $this->withToken($user->createToken('test-demande')->plainTextToken);
        $direction = Direction::factory()->create();
        $lieu = EntiteGeographique::factory()->create();
        $payload = ['direction_id' => $direction->id, 'type_sortie_id' => TypeSortie::factory()->create()->id, 'adresse_depart_id' => $lieu->id, 'date_depart' => '2026-11-03', 'heure_depart' => '08:00:00', 'motif' => 'Test de non-régression', 'destinations' => [['entite_geographique_id' => $lieu->id, 'ordre' => 1]], 'employes' => [['nom' => 'Test', 'prenom' => 'Transaction']], 'objets' => null];
        $created = $this->postJson('/api/demandes', $payload)->assertCreated();
        $id = $created->json('data.id');
        $this->putJson('/api/demandes/'.$id, [...$payload, 'motif' => 'Motif corrigé'])->assertOk();
        $this->postJson('/api/demandes/'.$id.'/soumission')->assertOk()->assertJsonPath('data.statut', 'EN_ATTENTE');
        $this->getJson('/api/demandes/'.$id)->assertOk()->assertJsonPath('data.motif', 'Motif corrigé');
        $this->getJson('/api/demandes/statut?reference='.urlencode($created->json('data.reference')))->assertOk()->assertJsonPath('data.statut', 'EN_ATTENTE');
        $this->getJson('/api/demandes?reference='.urlencode($created->json('data.reference')))->assertOk()->assertJsonPath('data.0.id', $id);
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'cancel_demande', 'guard_name' => 'web'], ['libelle' => 'Annuler une demande']));
        app('auth')->forgetGuards();
        $this->postJson('/api/demandes/'.$id.'/annulation', ['motif' => 'Annulation de test'])->assertOk()->assertJsonPath('data.statut', 'ANNULEE');
    }

    public function test_existing_demande_modification_endpoints_remain_compatible(): void
    {
        $user = User::factory()->create();
        foreach (['create_demande', 'update_demande', 'submit_demande', 'read_demande'] as $name) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['libelle' => $name]));
        }
        $this->withToken($user->createToken('test-revision')->plainTextToken);
        $direction = Direction::factory()->create();
        $lieu = EntiteGeographique::factory()->create();
        $payload = ['direction_id' => $direction->id, 'type_sortie_id' => TypeSortie::factory()->create()->id, 'adresse_depart_id' => $lieu->id, 'date_depart' => '2026-11-03', 'heure_depart' => '08:00:00', 'motif' => 'Original préservé', 'destinations' => [['entite_geographique_id' => $lieu->id, 'ordre' => 1]], 'employes' => [['nom' => 'Test', 'prenom' => 'Révision']], 'objets' => null];
        $id = $this->postJson('/api/demandes', $payload)->assertCreated()->json('data.id');
        Demande::query()->whereKey($id)->update(['statut' => Demande::STATUT_VALIDEE]);
        $this->postJson('/api/demandes/'.$id.'/modification')->assertOk()->assertJsonPath('data.statut', 'VALIDEE');
        $this->putJson('/api/demandes/'.$id.'/modification', [...$payload, 'motif' => 'Proposition différente'])->assertOk()->assertJsonPath('data.motif', 'Original préservé');
        $this->assertDatabaseHas('demande_modifications', ['demande_id' => $id, 'motif' => 'Proposition différente']);
        $this->postJson('/api/demandes/'.$id.'/modification/soumission')->assertOk()->assertJsonPath('data.statut', 'REVALIDATION_REQUISE');
    }

    public function test_existing_authentication_and_password_obligation_routes_remain_compatible(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->getJson('/api/me')->assertUnauthorized();
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->assertSame(['token', 'must_change_password'], array_keys($login->json('data')));
        $this->assertStringContainsString('|', $login->json('data.token'));
        app('auth')->forgetGuards();
        $this->withToken($login->json('data.token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/directions')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/logout')->assertOk()->assertExactJson(['message' => 'Déconnexion réussie.']);
        app('auth')->forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_each_operation_success_and_json_conform_to_openapi(string $method, string $template): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $isService = str_contains($template, 'services');
        $model = $isService ? Service::factory()->create(['direction_id' => $direction->id]) : $direction;
        if (str_ends_with($template, '/activate')) {
            $model->update(['statut' => 'INACTIF']);
        }
        $path = str_replace('/1', '/{id}', $template);
        $uri = str_replace('/1', '/'.$model->id, $template);
        $payload = [];
        if ($method === 'post') {
            $payload = ['nom' => 'Vérification de contrat', 'code' => 'CHECK_'.Str::random(16)];
            if ($isService) {
                $payload['direction_id'] = $direction->id;
            }
        } elseif ($method === 'put') {
            $payload = ['version_attendue' => 0];
            if (str_ends_with($template, '/activate') || str_ends_with($template, '/deactivate')) {
                $payload['motif'] = 'Vérification de statut';
            } else {
                $payload['nom'] = 'Nom corrigé par test';
            }
        } elseif (! str_contains($template, '/1')) {
            $uri .= '?'.http_build_query($isService ? ['direction_id' => $direction->id, 'per_page' => 1] : ['search' => $direction->code, 'per_page' => 1]);
        }

        $response = $method === 'get' ? $this->getJson($uri) : $this->command($method, $uri, $payload);
        $response->assertStatus($method === 'post' ? 201 : 200);
        $this->assertContractResponse($method, $path, $response);
        if ($method !== 'get') {
            $table = $isService ? 'services' : 'directions';
            $this->assertDatabaseHas($table, ['id' => $response->json('data.id'), 'nom' => $response->json('data.nom'), 'statut' => $response->json('data.statut')]);
        }
    }

    #[DataProvider('endpoints')]
    public function test_each_operation_checks_its_own_permission_and_administrative_role(string $method, string $template): void
    {
        $this->seed(OrganisationPermissionSeeder::class);
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $path = str_replace('/1', '/{id}', $template);
        $permission = $contract['paths'][$path][$method]['x-permissions'][0];
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $this->withToken($user->createToken('ordinary-reader')->plainTextToken);
        $uri = $template;
        if ($template === '/api/services' && $method === 'get') {
            $uri .= '?direction_id='.Direction::factory()->create()->id;
        }
        if (in_array($template, ['/api/directions', '/api/services'], true) && $method === 'get') {
            $response = $this->getJson($uri)->assertOk();
        } else {
            $response = $this->json($method, $uri)->assertForbidden();
        }
        $this->assertContractResponse($method, $path, $response);

        $admin = $this->admin();
        $admin->roles()->where('system_key', 'super_admin')->firstOrFail()->revokePermissionTo($permission);
        $response = $this->json($method, $uri)->assertForbidden();
        $this->assertContractResponse($method, $path, $response);
    }

    public static function mutationEndpoints(): array
    {
        return array_values(array_filter(self::endpoints(), fn (array $operation): bool => $operation[0] !== 'get'));
    }

    #[DataProvider('mutationEndpoints')]
    public function test_every_mutation_requires_header_key_and_rejects_it_in_json(string $method, string $template): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $isService = str_contains($template, 'services');
        $model = $isService ? Service::factory()->create(['direction_id' => $direction->id]) : $direction;
        $uri = str_replace('/1', '/'.$model->id, $template);
        $path = str_replace('/1', '/{id}', $template);
        $payload = $method === 'post' ? ['nom' => 'Contrôle clé', 'code' => 'CHECK_'.Str::random(16)] : ['version_attendue' => 0, 'motif' => 'Contrôle clé'];
        if ($method === 'post' && $isService) {
            $payload['direction_id'] = $direction->id;
        }
        $response = $this->json($method, $uri, $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertContractResponse($method, $path, $response);
        $response = $this->json($method, $uri, $payload, ['Idempotency-Key' => 'not-a-uuid'])->assertUnprocessable();
        $this->assertContractResponse($method, $path, $response);
        $response = $this->command($method, $uri, [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertContractResponse($method, $path, $response);
    }

    public static function versionedEndpoints(): array
    {
        return array_values(array_filter(self::endpoints(), fn (array $operation): bool => $operation[0] === 'put'));
    }

    public static function objectEndpoints(): array
    {
        return array_values(array_filter(self::endpoints(), fn (array $operation): bool => str_contains($operation[1], '/1')));
    }

    #[DataProvider('objectEndpoints')]
    public function test_missing_objects_return_the_documented_json_404(string $method, string $template): void
    {
        $this->admin();
        $uri = str_replace('/1', '/'.PHP_INT_MAX, $template);
        $path = str_replace('/1', '/{id}', $template);
        $response = $method === 'get' ? $this->getJson($uri) : $this->command($method, $uri, ['version_attendue' => 0, 'motif' => 'Objet inexistant']);
        $response->assertNotFound();
        $this->assertContractResponse($method, $path, $response);
    }

    #[DataProvider('versionedEndpoints')]
    public function test_each_existing_object_mutation_requires_version_and_replays_its_original_success(string $method, string $template): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $model = str_contains($template, 'services') ? Service::factory()->create(['direction_id' => $direction->id]) : $direction;
        $uri = str_replace('/1', '/'.$model->id, $template);
        $path = str_replace('/1', '/{id}', $template);
        if (str_ends_with($template, '/activate')) {
            $model->update(['statut' => 'INACTIF']);
        }
        $key = (string) Str::uuid();
        $payload = ['motif' => 'Commande versionnée'];
        if (str_ends_with($template, '/1')) {
            $payload['nom'] = 'Nom modifié une fois';
        }
        $response = $this->command($method, $uri, $payload, $key)->assertUnprocessable()->assertJsonValidationErrors('version_attendue');
        $this->assertContractResponse($method, $path, $response);
        $payload['version_attendue'] = 0;
        $first = $this->command($method, $uri, $payload, $key)->assertOk()->assertJsonPath('data.lock_version', 1);
        $replay = $this->command($method, $uri, $payload, $key)->assertOk()->assertExactJson($first->json());
        $this->assertContractResponse($method, $path, $replay);
        $conflict = $this->command($method, $uri, $payload)->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertContractResponse($method, $path, $conflict);
        $this->assertSame(1, $model->fresh()->lock_version);
        $this->assertSame(1, DB::table('organisation_audits')->where('object_type', str_contains($template, 'services') ? 'service' : 'direction')->where('object_id', $model->id)->count());
    }

    public static function nonIntegerVersions(): array
    {
        return [['0'], [false], [true], [0.5], [-1], [null]];
    }

    #[DataProvider('nonIntegerVersions')]
    public function test_version_must_be_a_non_negative_json_integer(mixed $value): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $response = $this->command('put', '/api/directions/'.$direction->id, ['version_attendue' => $value, 'nom' => 'Ne doit pas être enregistré'])->assertUnprocessable()->assertJsonValidationErrors('version_attendue');
        $this->assertContractResponse('put', '/api/directions/{id}', $response);
        $this->assertSame(0, $direction->fresh()->lock_version);
    }

    public function test_service_parent_id_must_be_a_json_integer(): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $response = $this->command('post', '/api/services', ['direction_id' => (string) $direction->id, 'nom' => 'Type parent', 'code' => 'CHECK_'.Str::random(16)])->assertUnprocessable()->assertJsonValidationErrors('direction_id');
        $this->assertContractResponse('post', '/api/services', $response);
    }

    public function test_empty_search_is_allowed_and_detail_does_not_validate_list_filters(): void
    {
        $this->admin();
        foreach (['/api/directions', '/api/parametrage/directions', '/api/parametrage/services'] as $path) {
            $response = $this->getJson($path.'?search=&per_page=1')->assertOk();
            $this->assertContractResponse('get', $path, $response);
        }
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        foreach ([['directions', $direction->id], ['services', $service->id]] as [$path, $id]) {
            $response = $this->getJson('/api/'.$path.'/'.$id.'?per_page=0')->assertOk();
            $this->assertContractResponse('get', '/api/'.$path.'/{id}', $response);
        }
    }

    public function test_key_can_be_retried_after_rolled_back_failure_and_not_after_loss_of_access(): void
    {
        $admin = $this->admin();
        $key = (string) Str::uuid();
        $payload = ['nom' => 'Reprise après panne', 'code' => 'CHECK_'.Str::random(16)];
        $injected = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$injected): void {
            if (! $injected && str_contains($sql, 'insert into `organisation_audits`')) {
                $injected = true;
                throw new QueryException('mysql', $sql, [], new \PDOException('Panne ponctuelle audit simulée'));
            }
        });
        $response = $this->command('post', '/api/directions', $payload, $key)->assertStatus(503);
        $this->assertContractResponse('post', '/api/directions', $response);
        $this->assertDatabaseMissing('organisation_commands', ['actor_id' => $admin->id, 'command_key' => $key]);
        $response = $this->command('post', '/api/directions', $payload, $key)->assertCreated();
        $this->assertContractResponse('post', '/api/directions', $response);
        $admin->update(['statut' => 'INACTIF']);
        $response = $this->command('post', '/api/directions', $payload, $key)->assertForbidden()->assertJsonMissingPath('data');
        $this->assertContractResponse('post', '/api/directions', $response);
        $this->assertSame(1, DB::table('organisation_commands')->where('actor_id', $admin->id)->where('command_key', $key)->count());
    }

    private function assertContractResponse(string $method, string $path, TestResponse $response): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $responses = $contract['paths'][$path][$method]['responses'];
        $this->assertArrayHasKey($response->status(), $responses, $method.' '.$path.' : HTTP non documenté');
        $definition = $this->resolveContractSchema($responses[$response->status()], $contract);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertContractSchema($definition['content']['application/json']['schema'], $response->json(), $contract);
    }

    private function resolveContractSchema(array $schema, array $contract): array
    {
        while (isset($schema['$ref'])) {
            $reference = $schema['$ref'];
            $schema = $contract;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $schema = $schema[$segment];
            }
        }

        return $schema;
    }

    private function assertContractSchema(array $schema, mixed $value, array $contract): void
    {
        $schema = $this->resolveContractSchema($schema, $contract);
        if ($value === null && ($schema['nullable'] ?? false)) {
            $this->assertNull($value);

            return;
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum']);
        }
        switch ($schema['type']) {
            case 'object':
                $this->assertIsArray($value);
                foreach ($schema['required'] ?? [] as $key) {
                    $this->assertArrayHasKey($key, $value);
                }
                if (($schema['additionalProperties'] ?? true) === false) {
                    $this->assertSame([], array_diff(array_keys($value), array_keys($schema['properties'] ?? [])));
                }
                foreach ($value as $key => $item) {
                    if (isset($schema['properties'][$key])) {
                        $this->assertContractSchema($schema['properties'][$key], $item, $contract);
                    } elseif (is_array($schema['additionalProperties'] ?? null)) {
                        $this->assertContractSchema($schema['additionalProperties'], $item, $contract);
                    }
                }
                break;
            case 'array':
                $this->assertIsArray($value);
                $this->assertTrue(array_is_list($value));
                foreach ($value as $item) {
                    $this->assertContractSchema($schema['items'], $item, $contract);
                }
                break;
            case 'integer':
                $this->assertIsInt($value);
                if (isset($schema['minimum'])) {
                    $this->assertGreaterThanOrEqual($schema['minimum'], $value);
                }
                if (isset($schema['maximum'])) {
                    $this->assertLessThanOrEqual($schema['maximum'], $value);
                }
                break;
            case 'string':
                $this->assertIsString($value);
                if (isset($schema['minLength'])) {
                    $this->assertGreaterThanOrEqual($schema['minLength'], mb_strlen($value));
                }
                if (isset($schema['maxLength'])) {
                    $this->assertLessThanOrEqual($schema['maxLength'], mb_strlen($value));
                }
                break;
            default:
                $this->fail('Type de schéma non pris en charge dans ces réponses : '.$schema['type']);
        }
    }

    private function admin(): User
    {
        $this->seed(OrganisationPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::query()->where('system_key', 'super_admin')->firstOrFail());
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('test-organisation')->plainTextToken);

        return $user;
    }

    private function command(string $method, string $uri, array $payload, ?string $key = null): TestResponse
    {
        return $this->json($method, $uri, $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
