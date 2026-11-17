<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ParametrageSecurity;
use App\Services\PasswordService;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\ParametrageSecuritySeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ParametrageSecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Base interdite.');
        }

        return $app;
    }

    private function admin(): User
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $actor = User::factory()->create(['password' => Hash::make('AdminPass123'), 'direction_id' => Direction::factory()->create()->id]);
        $actor->assignRole(Role::query()->where('system_key', 'super_admin')->firstOrFail());
        $this->token($actor);

        return $actor;
    }

    private function token(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function command(string $method, string $path, array $data = [], ?string $key = null): TestResponse
    {
        return $this->json($method, $path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public static function endpoints(): array
    {
        return [
            ['GET', '/api/users'], ['POST', '/api/users'], ['GET', '/api/users/1'], ['PUT', '/api/users/1'],
            ['PATCH', '/api/users/1/activate'], ['PATCH', '/api/users/1/deactivate'], ['POST', '/api/users/1/reset-password'], ['PUT', '/api/users/1/roles'],
            ['POST', '/api/users/1/temporary-password/reveal'], ['GET', '/api/roles'], ['POST', '/api/roles'], ['GET', '/api/roles/1'], ['PUT', '/api/roles/1'], ['DELETE', '/api/roles/1'], ['PUT', '/api/roles/1/permissions'],
            ['GET', '/api/permissions'], ['POST', '/api/permissions'], ['GET', '/api/permissions/1'], ['PUT', '/api/permissions/1'], ['DELETE', '/api/permissions/1'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_authentication_is_required(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_all_permissions_without_system_role_do_not_grant_administration(string $method, string $uri): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::query()->get());
        $this->token($user);
        $this->json($method, $uri)->assertForbidden();
    }

    public function test_create_user_is_atomic_idempotent_and_never_exposes_a_secret(): void
    {
        $this->admin();
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $data = ['nom' => 'Test', 'prenom' => 'Compte', 'email' => 'nouveau-parametrage@example.test', 'direction_id' => $direction->id, 'service_id' => $service->id];
        $key = (string) Str::uuid();
        $first = $this->command('POST', '/api/users', $data, $key)->assertCreated()->assertJsonMissingPath('data.temporary_password');
        $id = $first->json('data.id');
        $this->command('POST', '/api/users', $data, $key)->assertCreated()->assertExactJson($first->json());
        $user = User::findOrFail($id);
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->temporary_password);
        $this->assertTrue($user->roles()->where('system_key', 'demandeur')->exists());
        $this->assertSame($service->id, $user->service_id);
        $this->getJson('/api/users/'.$id)->assertOk()->assertJsonMissingPath('data.temporary_password')->assertJsonPath('data.lock_version', 0);
        $this->getJson('/api/users?search=nouveau-parametrage')->assertOk()->assertJsonMissingPath('data.0.temporary_password');
        $this->assertSame(1, DB::table('user_organisation_histories')->where('user_id', $id)->count());
        $this->assertSame(1, DB::table('organisation_audits')->where('object_type', 'user')->where('object_id', $id)->where('action', 'create_user')->count());
        $this->assertSame(1, DB::table('organisation_commands')->where('command_key', $key)->count());
        $this->command('POST', '/api/users', [...$data, 'nom' => 'Autre'], $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_versions_and_header_are_required_and_stale_version_is_rejected(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $this->putJson('/api/users/'.$user->id, ['version_attendue' => 0])->assertUnprocessable();
        $this->command('PUT', '/api/users/'.$user->id)->assertUnprocessable();
        $this->command('PUT', '/api/users/'.$user->id, ['version_attendue' => '0'])->assertUnprocessable();
        $key = (string) Str::uuid();
        $data = ['nom' => 'Modifié', 'version_attendue' => 0];
        $first = $this->command('PUT', '/api/users/'.$user->id, $data, $key)->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->command('PUT', '/api/users/'.$user->id, $data, $key)->assertOk()->assertExactJson($first->json());
        $this->command('PUT', '/api/users/'.$user->id, ['nom' => 'Périmé', 'version_attendue' => 0])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertSame('Modifié', $user->fresh()->nom);
    }

    public function test_last_admin_deactivation_and_role_removal_are_rolled_back(): void
    {
        $actor = $this->admin();
        $role = Role::where('system_key', 'super_admin')->firstOrFail();
        foreach ($role->users()->where('users.id', '!=', $actor->id)->get() as $other) {
            $other->update(['statut' => 'INACTIF']);
        }
        $before = DB::table('organisation_commands')->count();
        $this->command('PATCH', '/api/users/'.$actor->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Test sécurité'])->assertConflict()->assertJsonPath('code', 'LAST_EFFECTIVE_ADMIN');
        $this->command('PUT', '/api/users/'.$actor->id.'/roles', ['version_attendue' => 0, 'motif' => 'Retrait test', 'role_ids' => []])->assertConflict()->assertJsonPath('code', 'LAST_EFFECTIVE_ADMIN');
        $this->assertSame('ACTIF', $actor->fresh()->statut);
        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
        $this->assertSame($before, DB::table('organisation_commands')->count());
        $this->assertTrue($actor->tokens()->exists());
    }

    public function test_password_obligation_does_not_remove_a_recoverable_admin_from_the_count(): void
    {
        $actor = $this->admin();
        $other = User::factory()->create(['must_change_password' => true]);
        $other->assignRole(Role::where('system_key', 'super_admin')->firstOrFail());
        foreach (Role::where('system_key', 'super_admin')->firstOrFail()->users()->whereNotIn('users.id', [$actor->id, $other->id])->get() as $legacy) {
            $legacy->update(['statut' => 'INACTIF']);
        }
        $this->command('PATCH', '/api/users/'.$actor->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Autre admin présent'])->assertOk();
        $this->assertSame('INACTIF', $actor->fresh()->statut);
        $this->assertSame(0, $actor->tokens()->count());
    }

    public function test_system_roles_and_socles_are_protected(): void
    {
        $this->admin();
        foreach (['super_admin', 'demandeur'] as $key) {
            $role = Role::where('system_key', $key)->firstOrFail();
            foreach ([['nom' => 'modifie'], ['statut' => 'INACTIF']] as $change) {
                $this->command('PUT', '/api/roles/'.$role->id, [...$change, 'version_attendue' => $role->lock_version, 'motif' => 'Test protection'])->assertConflict()->assertJsonPath('code', 'SYSTEM_OBJECT_PROTECTED');
            }
            $this->command('PUT', '/api/roles/'.$role->id.'/permissions', ['permission_ids' => [], 'version_attendue' => $role->lock_version, 'motif' => 'Test retrait'])->assertConflict();
            $this->delete('/api/roles/'.$role->id, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => (string) $role->lock_version, 'X-Change-Reason' => rawurlencode('Test suppression')])->assertConflict();
            $this->assertSame('ACTIF', $role->fresh()->statut);
        }
    }

    public function test_role_lifecycle_and_empty_attributions(): void
    {
        $this->admin();
        $role = $this->command('POST', '/api/roles', ['nom' => 'role_test_parametrage', 'description' => 'Test'])->assertCreated()->json('data.id');
        $user = User::factory()->create();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', ['role_ids' => [$role], 'version_attendue' => 0, 'motif' => 'Test attribution'])->assertOk();
        $this->command('PUT', '/api/roles/'.$role.'/permissions', ['permission_ids' => [], 'version_attendue' => 0, 'motif' => 'Test liste vide'])->assertOk();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', ['role_ids' => [], 'version_attendue' => 1, 'motif' => 'Retrait test'])->assertOk();
        $this->delete('/api/roles/'.$role, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => rawurlencode('Suppression test')])->assertConflict();
        $role = $this->command('POST', '/api/roles', ['nom' => 'jamais_attribue'])->assertCreated()->json('data.id');
        $this->delete('/api/roles/'.$role, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => rawurlencode('Suppression test')])->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role]);
    }

    public function test_role_suspension_is_immediate_even_with_loaded_relations_and_legacy_direct_rights_remain(): void
    {
        $this->admin();
        $role = Role::factory()->create(['statut' => 'ACTIF']);
        $permission = Permission::where('name', 'read_demande')->firstOrFail();
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->load('roles.permissions', 'permissions');
        $this->assertTrue($user->can('read_demande'));
        $this->command('PUT', '/api/roles/'.$role->id, ['statut' => 'INACTIF', 'version_attendue' => 0, 'motif' => 'Suspendre le rôle'])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->assertFalse($user->can('read_demande'));
        $this->assertFalse($user->getAllPermissions()->contains('name', 'read_demande'));
        $this->token($user);
        $this->getJson('/api/demandes')->assertForbidden();
        $user->givePermissionTo($permission);
        $this->assertTrue($user->can('read_demande'));
        $this->getJson('/api/demandes')->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.permissions_effectives', ['read_demande']);
    }

    public function test_an_inactive_owned_role_can_be_retained_but_not_newly_assigned(): void
    {
        $this->admin();
        $role = Role::factory()->create(['statut' => 'INACTIF']);
        $owner = User::factory()->create();
        $owner->assignRole($role);
        $new = User::factory()->create();
        $data = ['role_ids' => [$role->id], 'version_attendue' => 0, 'motif' => 'Test rôle inactif'];
        $this->command('PUT', '/api/users/'.$owner->id.'/roles', $data)->assertOk();
        $this->command('PUT', '/api/users/'.$new->id.'/roles', $data)->assertUnprocessable();
    }

    public function test_nonexistent_attribution_ids_return_404(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', ['role_ids' => [PHP_INT_MAX], 'version_attendue' => 0, 'motif' => 'Test référence'])->assertNotFound();
        $this->command('PUT', '/api/roles/'.$role->id.'/permissions', ['permission_ids' => [PHP_INT_MAX], 'version_attendue' => 0, 'motif' => 'Test référence'])->assertNotFound();
    }

    public function test_reveal_reset_and_change_password_preserve_confidentiality_and_revoke_tokens(): void
    {
        $actor = $this->admin();
        $user = User::factory()->create();
        $old = $user->createToken('ancien')->plainTextToken;
        $key = (string) Str::uuid();
        $data = ['version_attendue' => 0, 'motif' => 'Reset test', 'actor_password' => 'AdminPass123'];
        $first = $this->command('POST', '/api/users/'.$user->id.'/reset-password', $data, $key)->assertOk()->assertJsonMissingPath('data.temporary_password')->assertJsonPath('data.password_generation', 2);
        $this->command('POST', '/api/users/'.$user->id.'/reset-password', $data, $key)->assertOk()->assertExactJson($first->json());
        $this->assertSame(0, $user->tokens()->count());
        $reveal = ['version_attendue' => 1, 'password_generation' => 2, 'motif' => 'Communication test', 'current_password' => 'AdminPass123'];
        $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', [...$reveal, 'champ_inattendu' => 'ne pas accepter'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('champ_inattendu');
        $response = $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', $reveal)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $secret = $response->json('data.temporary_password');
        $this->assertSame(12, mb_strlen($secret));
        $this->assertTrue(Hash::check($secret, $user->fresh()->password));
        $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', $reveal)->assertOk();
        $this->assertSame(2, DB::table('organisation_audits')->where('object_id', $user->id)->where('object_type', 'user')->where('action', 'reveal')->count());
        $persisted = json_encode(DB::table('organisation_commands')->where('actor_id', $actor->id)->get()).json_encode(DB::table('organisation_audits')->where('actor_id', $actor->id)->get());
        $this->assertStringNotContainsString($secret, $persisted);
        $this->assertStringNotContainsString('AdminPass123', $persisted);
        $this->assertStringNotContainsString($user->fresh()->password, $persisted);
        $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', [...$reveal, 'password_generation' => 1])->assertConflict();
        app('auth')->forgetGuards();
        $this->withToken($old)->getJson('/api/me')->assertUnauthorized();
        app('auth')->forgetGuards();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => $secret])->assertOk()->json('data.token');
        app('auth')->forgetGuards();
        $this->withToken($token)->putJson('/api/me/password', ['new_password' => 'Nouveau123!', 'new_password_confirmation' => 'Nouveau123!'])->assertOk();
        $this->assertNull($user->fresh()->temporary_password);
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_wrong_reauthentication_is_limited_and_audited_without_its_value(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $data = ['version_attendue' => 0, 'password_generation' => 1, 'motif' => 'Test refus', 'current_password' => 'incorrect'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', $data)->assertUnauthorized();
        }
        $limited = $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', $data)
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertHeader('Retry-After');
        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(0, $user->fresh()->lock_version);
        $this->assertStringNotContainsString('incorrect', json_encode(DB::table('organisation_audits')->where('object_id', $user->id)->get()));
    }

    public function test_organisation_validation_and_history_preserve_unchanged_inactive_links(): void
    {
        $this->admin();
        $oldDirection = Direction::factory()->create(['statut' => 'INACTIF']);
        $newDirection = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $newDirection->id]);
        $user = User::factory()->create(['direction_id' => $oldDirection->id]);
        $this->command('PUT', '/api/users/'.$user->id, ['nom' => 'Corrigé', 'version_attendue' => 0])->assertOk();
        $this->command('PUT', '/api/users/'.$user->id, ['direction_id' => $newDirection->id, 'service_id' => $service->id, 'version_attendue' => 1, 'motif' => 'Rattachement test'])->assertOk();
        $history = DB::table('user_organisation_histories')->where('user_id', $user->id)->first();
        $this->assertSame($oldDirection->nom, json_decode($history->before_values, true)['direction']['nom']);
        $this->assertSame($service->code, json_decode($history->after_values, true)['service']['code']);
        $this->command('PUT', '/api/users/'.$user->id, ['direction_id' => $oldDirection->id, 'service_id' => null, 'version_attendue' => 2, 'motif' => 'Inactif interdit'])->assertUnprocessable();
    }

    public function test_super_admin_assignment_requires_actor_password(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $role = Role::where('system_key', 'super_admin')->firstOrFail();
        $data = ['role_ids' => [$role->id], 'version_attendue' => 0, 'motif' => 'Test nomination'];
        $this->command('PUT', '/api/users/'.$user->id.'/roles', $data)->assertUnprocessable();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', [...$data, 'actor_password' => 'incorrect'])->assertUnauthorized();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', [...$data, 'actor_password' => 'AdminPass123'])->assertOk();
    }

    public function test_user_action_payloads_are_strict_and_password_reset_matches_its_contract(): void
    {
        $this->admin();
        $user = User::factory()->create();

        $this->command('PATCH', '/api/users/'.$user->id.'/activate', [
            'version_attendue' => 0,
            'motif' => 'Activation contrôlée',
            'statut' => 'INACTIF',
        ])->assertUnprocessable();
        $this->assertSame('ACTIF', $user->fresh()->statut);

        $this->command('PATCH', '/api/users/'.$user->id.'/deactivate', [
            'version_attendue' => 0,
            'motif' => 'Désactivation contrôlée',
            'email' => 'intrus@example.test',
        ])->assertUnprocessable();
        $this->assertSame('ACTIF', $user->fresh()->statut);

        $activatedUser = User::factory()->create(['statut' => 'INACTIF']);
        $key = (string) Str::uuid();
        $activation = [
            'version_attendue' => 0,
            'motif' => 'Activation contrôlée',
        ];
        $this->command('PATCH', '/api/users/'.$activatedUser->id.'/activate', $activation, $key)
            ->assertOk()->assertJsonPath('data.statut', 'ACTIF')->assertJsonPath('data.lock_version', 1);
        $this->command('PATCH', '/api/users/'.$activatedUser->id.'/activate', [...$activation, 'extra' => true], $key)
            ->assertUnprocessable();

        $role = Role::factory()->create();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', [
            'role_ids' => [$role->id],
            'version_attendue' => 0,
            'motif' => 'Attribution contrôlée',
            'extra' => true,
        ])->assertUnprocessable();
        $this->assertFalse($user->fresh()->roles()->whereKey($role->id)->exists());

        $this->command('POST', '/api/users/'.$user->id.'/reset-password', [
            'version_attendue' => 0,
            'motif' => 'Reset contrôlé',
            'actor_password' => 'AdminPass123',
            'password' => 'valeur interdite',
        ])->assertUnprocessable();
        $this->assertSame(1, (int) $user->fresh()->password_generation);

        $response = $this->command('POST', '/api/users/'.$user->id.'/reset-password', [
            'version_attendue' => 0,
            'motif' => 'Reset contrôlé',
            'actor_password' => 'AdminPass123',
        ])->assertOk();

        $this->assertSame(
            ['must_change_password', 'temporary_password_available', 'password_generation', 'lock_version'],
            array_keys($response->json('data')),
        );
        $this->assertSame(true, $response->json('data.temporary_password_available'));
        $this->assertSame(2, $response->json('data.password_generation'));
        $this->assertSame(1, $response->json('data.lock_version'));
        $this->assertArrayNotHasKey('temporary_password', $response->json('data'));

        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $expectedKeys = array_keys($contract['components']['schemas']['ParamPasswordResetData']['properties']);
        $actualKeys = array_keys($response->json('data'));
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function test_permission_application_codes_and_direct_dependencies_are_protected(): void
    {
        $this->admin();
        $permission = Permission::where('name', 'read_user')->firstOrFail();
        $this->command('PUT', '/api/permissions/'.$permission->id, ['code' => 'code_modifie', 'version_attendue' => 0])->assertConflict();
        $custom = Permission::factory()->create();
        User::factory()->create()->givePermissionTo($custom);
        $this->delete('/api/permissions/'.$custom->id, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => 'Test'])
            ->assertConflict()->assertJsonPath('code', 'REFERENCE_IN_USE');
        $this->assertDatabaseHas('permissions', ['id' => $custom->id]);
    }

    public function test_role_and_permission_commands_reject_unknown_fields_before_replay(): void
    {
        $this->admin();

        $this->command('POST', '/api/roles', ['nom' => 'role_injection', 'system_key' => 'super_admin'])->assertUnprocessable();
        $this->assertDatabaseMissing('roles', ['name' => 'role_injection']);

        $this->command('POST', '/api/permissions', [
            'code' => 'permission_injection',
            'libelle' => 'Permission test',
            'guard_name' => 'api',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('permissions', ['name' => 'permission_injection']);

        $role = Role::factory()->create(['description' => 'Avant']);
        $key = (string) Str::uuid();
        $update = ['description' => 'Après', 'version_attendue' => 0, 'motif' => 'Mise à jour test'];
        $this->command('PUT', '/api/roles/'.$role->id, $update, $key)->assertOk();
        $this->command('PUT', '/api/roles/'.$role->id, [...$update, 'system_key' => 'super_admin'], $key)->assertUnprocessable();
        $this->assertSame('Après', $role->fresh()->description);

        $permission = Permission::factory()->create();
        $this->command('PUT', '/api/roles/'.$role->id.'/permissions', [
            'permission_ids' => [$permission->id],
            'version_attendue' => 1,
            'motif' => 'Attribution test',
            'name' => 'forbidden',
        ])->assertUnprocessable();
        $this->assertFalse($role->fresh()->permissions()->whereKey($permission->id)->exists());

        $originalDescription = $permission->description;
        $this->command('PUT', '/api/permissions/'.$permission->id, [
            'description' => 'Ne doit pas être écrasée',
            'version_attendue' => 0,
            'guard_name' => 'api',
        ])->assertUnprocessable();
        $this->assertSame($originalDescription, $permission->fresh()->description);
    }

    public function test_lost_permission_blocks_replay_and_list_filters_are_validated(): void
    {
        $actor = $this->admin();
        $role = Role::factory()->create();
        $key = (string) Str::uuid();
        $data = ['description' => 'Révision test', 'version_attendue' => 0];
        $this->command('PUT', '/api/roles/'.$role->id, $data, $key)->assertOk();
        $this->getJson('/api/roles?per_page=101')->assertUnprocessable();
        $this->getJson('/api/users?per_page=101')->assertUnprocessable();
        $this->getJson('/api/roles?search='.$role->name.'&statut=ACTIF&per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
        $actor->roles()->where('system_key', 'super_admin')->firstOrFail()->revokePermissionTo('update_role');
        $this->command('PUT', '/api/roles/'.$role->id, $data, $key)->assertForbidden();
    }

    public function test_user_read_filters_reject_empty_integer_values_and_missing_users_return_openapi_codes(): void
    {
        $this->admin();

        $this->getJson('/api/users?per_page=')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/users/999999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_missing_guard_blocks_mutations_without_partial_writes(): void
    {
        $this->admin();
        $user = User::factory()->create();
        DB::table('organisation_guards')->where('id', 1)->delete();
        $this->command('PUT', '/api/users/'.$user->id, ['nom' => 'Non validé', 'version_attendue' => 0])->assertStatus(503);
        $this->assertSame(0, $user->fresh()->lock_version);
    }

    public function test_string_role_id_cannot_bypass_super_admin_reauthentication(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $super = Role::where('system_key', 'super_admin')->firstOrFail();
        $this->command('PUT', '/api/users/'.$user->id.'/roles', ['role_ids' => [(string) $super->id], 'version_attendue' => 0, 'motif' => 'Test type'])->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasRole('super_admin'));
    }

    public function test_token_cannot_be_issued_from_credentials_read_before_a_reset(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $stale = $user->fresh();
        app(PasswordService::class)->reset($user);
        try {
            app(AuthService::class)->createToken($stale);
            $this->fail('Un état de credentials périmé ne doit pas émettre de jeton.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_audit_storage_failure_rolls_back_profile_and_command(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $triggered = false;
        DB::listen(function (QueryExecuted $query) use (&$triggered): void {
            if (! $triggered && str_starts_with($query->sql, 'insert into `organisation_audits`')) {
                $triggered = true;
                throw new QueryException('mysql', 'audit storage failure', [], new \PDOException('simulated'));
            }
        });
        $this->command('PUT', '/api/users/'.$user->id, ['nom' => 'Non conservé', 'version_attendue' => 0], $key)->assertStatus(503);
        $this->assertTrue($triggered);
        $this->assertSame(0, $user->fresh()->lock_version);
        $this->assertDatabaseMissing('organisation_commands', ['command_key' => $key]);
    }

    public function test_existing_admin_bootstrap_preserves_credentials_status_and_additional_roles(): void
    {
        $this->admin();
        $user = User::query()->where('email', 'admin@aninf.ga')->first() ?? User::factory()->create(['email' => 'admin@aninf.ga']);
        $extra = Role::factory()->create();
        $user->assignRole($extra);
        $before = $user->only(['password', 'statut', 'direction_id', 'must_change_password']);
        $this->seed(AdminUserSeeder::class);
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        $this->assertTrue($user->fresh()->roles()->whereKey($extra->id)->exists());
        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }

    public function test_new_admin_bootstrap_refuses_to_invent_an_official_direction(): void
    {
        $this->admin();
        $existing = User::where('email', 'admin@aninf.ga')->first();
        if ($existing !== null) {
            $existing->update(['email' => 'ancien-admin-test@example.test']);
        }
        config(['aninf.initial_admin_password' => 'Initial123!', 'aninf.initial_admin_direction_id' => null]);
        try {
            $this->seed(AdminUserSeeder::class);
            $this->fail('Un organigramme officiel doit être fourni.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('direction officielle', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'admin@aninf.ga']);
    }

    public function test_contract_matches_live_resources_and_all_routes(): void
    {
        $actor = $this->admin();
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = strtolower($method).' /'.$route->uri();
            }
        }
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)
                    && in_array($operation['x-implementation-status'] ?? null, ['implemented', 'partial'], true)) {
                    $expected[] = $method.' '.$path;
                }
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        foreach ([
            '/api/parametrage/types-sortie' => 'get',
            '/api/types-sortie/{id}/activate' => 'put',
            '/api/types-sortie/{id}/deactivate' => 'put',
            '/api/parametrage/entites-geographiques' => 'get',
            '/api/entites-geographiques/{id}/activate' => 'put',
            '/api/entites-geographiques/{id}/deactivate' => 'put',
            '/api/users/{id}/temporary-password/reveal' => 'post',
        ] as $path => $method) {
            $operation = $contract['paths'][$path][$method];
            $this->assertSame('implemented', $operation['x-implementation-status']);
            $this->assertSame('implemented', $operation['x-contract-status']);
            $this->assertStringNotContainsString('ROUTE ABSENTE', $operation['description']);
            $this->assertArrayHasKey('422', $operation['responses']);
        }
        $revealRateLimit = $contract['paths']['/api/users/{id}/temporary-password/reveal']['post']['responses']['429'];
        $this->assertArrayHasKey('Retry-After', $revealRateLimit['headers']);
        $this->assertSame('TOO_MANY_ATTEMPTS', $revealRateLimit['content']['application/json']['example']['code']);
        foreach (['ParamUser' => '/api/users/'.$actor->id, 'ParamRole' => '/api/roles/'.Role::where('system_key', 'super_admin')->firstOrFail()->id, 'ParamPermission' => '/api/permissions/'.Permission::firstOrFail()->id] as $schema => $path) {
            $response = $this->getJson($path)->assertOk();
            $keys = array_keys($contract['components']['schemas'][$schema]['properties']);
            sort($keys);
            $actualKeys = array_keys($response->json('data'));
            sort($actualKeys);
            $this->assertSame($keys, $actualKeys);
            $this->assertIsInt($response->json('data.lock_version'));
        }
        foreach (['users', 'roles', 'permissions'] as $path) {
            $this->getJson('/api/'.$path.'?per_page=101')->assertUnprocessable();
            $this->assertArrayHasKey('422', $contract['paths']['/api/'.$path]['get']['responses']);
            $response = $this->getJson('/api/'.$path.'?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
            $schema = $contract['paths']['/api/'.$path]['get']['responses']['200']['content']['application/json']['schema']['$ref'];
            $this->assertArrayHasKey('meta', $contract['components']['schemas'][basename($schema)]['properties']);
        }
        $this->assertArrayNotHasKey('temporary_password', $contract['components']['schemas']['ParamUser']['properties']);
        foreach (['users', 'roles', 'permissions'] as $path) {
            $this->getJson('/api/'.$path.'/invalide')->assertNotFound();
        }
    }

    public function test_test_data_seeder_cannot_remove_socles_or_reset_existing_credentials(): void
    {
        $this->admin();
        config(['aninf.initial_admin_password' => 'Initial123!']);
        if (! User::where('email', 'admin@aninf.ga')->exists()) {
            $user = User::factory()->create(['email' => 'admin@aninf.ga']);
            $user->assignRole(Role::where('system_key', 'super_admin')->firstOrFail());
        }
        $this->seed(TestDataSeeder::class);
        $user = User::where('email', 'demandeur1@aninf.ga')->firstOrFail();
        $extra = Role::factory()->create();
        $user->assignRole($extra);
        $before = $user->only(['password', 'statut', 'direction_id', 'must_change_password']);
        $this->seed(TestDataSeeder::class);
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        $this->assertTrue($user->fresh()->roles()->whereKey($extra->id)->exists());
        $demandeur = Role::where('system_key', 'demandeur')->firstOrFail();
        $this->assertSame([], array_diff(ParametrageSecurity::demandeurPermissions(), $demandeur->permissions()->pluck('name')->all()));
        app(ParametrageSecurity::class)->assertAdministratorRemains();
    }

    public function test_permission_and_role_deletion_never_detach_current_assignments(): void
    {
        $this->admin();
        $userAssignedRole = Role::factory()->create();
        $user = User::factory()->create();
        $user->assignRole($userAssignedRole);
        $userHeaders = ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => 'Test'];
        $this->delete('/api/roles/'.$userAssignedRole->id, [], $userHeaders)->assertConflict()->assertJsonPath('code', 'REFERENCE_IN_USE');
        $this->assertTrue($user->fresh()->roles()->whereKey($userAssignedRole->id)->exists());

        $role = Role::factory()->create();
        $permission = Permission::factory()->create();
        $role->givePermissionTo($permission);
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => 'Test'];
        $this->delete('/api/roles/'.$role->id, [], $headers)->assertConflict()->assertJsonPath('code', 'REFERENCE_IN_USE');
        $this->delete('/api/permissions/'.$permission->id, [], [...$headers, 'Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'REFERENCE_IN_USE');
        $this->assertTrue($role->fresh()->permissions()->whereKey($permission->id)->exists());

        $unusedPermission = Permission::factory()->create();
        $this->delete('/api/permissions/'.$unusedPermission->id, [], [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Expected-Version' => '0',
            'X-Change-Reason' => 'Suppression test',
        ])->assertNoContent();
        $this->assertDatabaseMissing('permissions', ['id' => $unusedPermission->id]);
    }

    public function test_inactive_account_with_a_legacy_token_cannot_access_authenticated_routes(): void
    {
        $user = User::factory()->create(['statut' => 'INACTIF']);
        $this->token($user);
        $this->getJson('/api/me')->assertForbidden();
        $this->postJson('/api/logout')->assertForbidden();
        $this->putJson('/api/me/password', ['new_password' => 'Nouveau123!', 'new_password_confirmation' => 'Nouveau123!'])->assertForbidden();
    }

    public function test_seeder_is_additive_and_preserves_business_rights_and_user_roles(): void
    {
        $this->admin();
        $super = Role::where('system_key', 'super_admin')->firstOrFail();
        $permission = Permission::factory()->create();
        $super->givePermissionTo($permission);
        $before = $super->permissions()->count();
        $this->seed(ParametrageSecuritySeeder::class);
        $this->seed(ParametrageSecuritySeeder::class);
        $this->assertSame($before, $super->fresh()->permissions()->count());
        $this->assertTrue($super->fresh()->hasPermissionTo($permission));
        $demandeur = Role::where('system_key', 'demandeur')->firstOrFail();
        $this->assertSame([], array_diff(ParametrageSecurity::demandeurPermissions(), $demandeur->permissions()->pluck('name')->all()));
    }
}
