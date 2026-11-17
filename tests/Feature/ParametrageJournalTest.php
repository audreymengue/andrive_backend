<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ParametrageJournalTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Les consultations sont testées exclusivement sur mysql/aninf_test.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Base interdite.');
        }

        return $app;
    }

    private function admin(): User
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::where('system_key', 'super_admin')->firstOrFail());
        $this->token($user);

        return $user;
    }

    private function token(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    /** @return list<array{string, string}> */
    public static function endpoints(): array
    {
        return [
            ['/api/users/1/organisation-history', 'read_user'],
            ['/api/parametrage/audit', 'read_parametrage_audit'],
            ['/api/parametrage/audit/administration:1', 'read_parametrage_audit'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_read_requires_authentication(string $uri, string $permission): void
    {
        $this->getJson($uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_an_ordinary_user_with_the_permission_cannot_consult_the_journal(string $uri, string $permission): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $this->token($user);
        $this->getJson($uri)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    #[DataProvider('endpoints')]
    public function test_super_admin_still_requires_the_specific_read_permission(string $uri, string $permission): void
    {
        $this->admin();
        Role::where('system_key', 'super_admin')->firstOrFail()->revokePermissionTo($permission);
        $this->getJson($uri)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    #[DataProvider('endpoints')]
    public function test_password_change_is_required_before_consultation(string $uri, string $permission): void
    {
        $user = $this->admin();
        $user->update(['must_change_password' => true]);
        $this->getJson($uri)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    #[DataProvider('endpoints')]
    public function test_disabled_accounts_and_system_roles_cannot_consult(string $uri, string $permission): void
    {
        $user = $this->admin();
        $user->update(['statut' => 'INACTIF']);
        $this->getJson($uri)->assertForbidden();
        $user->update(['statut' => 'ACTIF']);
        Role::where('system_key', 'super_admin')->firstOrFail()->update(['statut' => 'INACTIF']);
        $this->getJson($uri)->assertForbidden();
    }

    /** @param array<string, mixed> $values */
    private function audit(User $actor, array $values = []): int
    {
        return DB::table('organisation_audits')->insertGetId([
            'actor_id' => $actor->id, 'action' => 'update_user', 'object_type' => 'user', 'object_id' => $actor->id,
            'motif' => 'Correction de test', 'before_values' => json_encode(['nom' => 'Ancien']),
            'after_values' => json_encode(['nom' => 'Nouveau']), 'command_key' => (string) Str::uuid(),
            'created_at' => '2026-10-20 12:00:00', ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function maintenance(User $target, array $values = []): int
    {
        return DB::table('parametrage_maintenance_audits')->insertGetId([
            'origine' => ParametrageSecuritySeeder::class, 'object_type' => 'user', 'object_id' => $target->id,
            'before_values' => null, 'after_values' => json_encode(['statut' => 'ACTIF']),
            'command_key' => (string) Str::uuid(), 'created_at' => '2026-10-20 12:00:00', ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function history(User $user, array $values = []): int
    {
        return DB::table('user_organisation_histories')->insertGetId([
            'user_id' => $user->id, 'actor_id' => null, 'origine' => 'observation_initiale', 'motif' => null,
            'before_values' => null, 'after_values' => json_encode(['direction_id' => null, 'service_id' => null, 'direction' => null, 'service' => null]),
            'command_key' => null, 'created_at' => '2026-10-20 12:00:00', ...$values,
        ]);
    }

    public function test_history_is_scoped_paginated_and_preserves_recorded_snapshots(): void
    {
        $actor = $this->admin();
        $target = User::factory()->create();
        $other = User::factory()->create();
        $this->history($other);
        $direction = Direction::factory()->create(['nom' => 'Ancien nom de test']);
        $first = $this->history($target, ['after_values' => json_encode(['direction_id' => $direction->id, 'service_id' => null, 'direction' => ['id' => $direction->id, 'nom' => $direction->nom, 'code' => $direction->code], 'service' => null])]);
        $second = $this->history($target, ['actor_id' => $actor->id, 'origine' => 'administration', 'motif' => 'Nouveau rattachement', 'created_at' => '2026-10-21 12:00:00']);
        $direction->update(['nom' => 'Nouveau nom', 'statut' => 'INACTIF']);
        $target->update(['statut' => 'INACTIF']);
        $response = $this->getJson('/api/users/'.$target->id.'/organisation-history?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.after_values.direction.nom', 'Ancien nom de test');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull($response->json('data.0.actor_id'));
        $this->assertNull($response->json('data.0.before_values'));
        $this->assertSame('observation_initiale', $response->json('data.0.origine'));
        $this->getJson('/api/users/'.$target->id.'/organisation-history?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $second);
        $this->getJson('/api/users/'.$target->id.'/organisation-history?per_page=1&page=3')->assertOk()->assertJsonPath('data', []);
    }

    public function test_existing_user_without_history_returns_an_empty_list_and_missing_user_returns_404(): void
    {
        $this->admin();
        $target = User::factory()->create();
        $this->getJson('/api/users/'.$target->id.'/organisation-history')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/users/'.PHP_INT_MAX.'/organisation-history')->assertNotFound();
    }

    public function test_audit_merges_both_sources_without_id_collisions_and_has_stable_pagination(): void
    {
        $actor = $this->admin();
        $id = max((int) DB::table('organisation_audits')->max('id'), (int) DB::table('parametrage_maintenance_audits')->max('id')) + 100;
        $this->audit($actor, ['id' => $id]);
        $this->maintenance($actor, ['id' => $id]);
        $query = '?object_type=user&object_id='.$actor->id.'&per_page=1';
        $list = $this->getJson('/api/parametrage/audit'.$query)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', 'administration:'.$id)->assertJsonPath('data.0.resultat', 'SUCCES');
        $detail = $this->getJson('/api/parametrage/audit/administration:'.$id)->assertOk();
        $this->assertSame($list->json('data.0'), $detail->json('data'));
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));
        $this->getJson('/api/parametrage/audit'.$query.'&page=2')->assertOk()->assertJsonPath('data.0.id', 'maintenance:'.$id)->assertJsonPath('data.0.actor_id', null)->assertJsonPath('data.0.action', null)->assertJsonPath('data.0.origine', ParametrageSecuritySeeder::class);
        $this->getJson('/api/parametrage/audit/maintenance:'.$id)->assertOk()->assertJsonPath('data.resultat', 'SUCCES');
    }

    public function test_filters_cover_date_action_author_and_target_including_date_boundaries(): void
    {
        $actor = $this->admin();
        $other = User::factory()->create();
        $start = $this->audit($actor, ['created_at' => '2026-10-20 00:00:00']);
        $end = $this->audit($actor, ['created_at' => '2026-10-20 23:59:59']);
        $this->audit($actor, ['created_at' => '2026-10-21 00:00:00']);
        $this->audit($other, ['object_id' => $actor->id]);
        $this->audit($actor, ['action' => 'refus', 'after_values' => json_encode(['operation' => 'update_user'])]);
        $this->audit($actor, ['object_type' => 'role']);
        $this->maintenance($actor);
        $query = http_build_query(['date_debut' => '2026-10-20', 'date_fin' => '2026-10-20', 'action' => 'update_user', 'actor_id' => $actor->id, 'object_type' => 'user', 'object_id' => $actor->id]);
        $this->getJson('/api/parametrage/audit?'.$query)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', 'administration:'.$end)->assertJsonPath('data.1.id', 'administration:'.$start);
        $this->getJson('/api/parametrage/audit?'.http_build_query(['action' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('data', []);
    }

    public function test_single_date_bounds_include_maintenance_and_unknown_authors_return_empty_results(): void
    {
        $actor = $this->admin();
        $target = User::factory()->create();
        $this->maintenance($target, ['created_at' => '2026-10-21 12:00:00']);
        $base = '/api/parametrage/audit?object_type=user&object_id='.$target->id;
        $this->getJson($base.'&date_debut=2026-10-21')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($base.'&date_fin=2026-10-20')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($base.'&date_fin=2026-10-21')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($base.'&actor_id='.$actor->id)->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/parametrage/audit?actor_id='.PHP_INT_MAX)->assertOk()->assertJsonPath('data', []);
    }

    public static function invalidFilters(): array
    {
        return [
            ['per_page', 0], ['per_page', 101], ['per_page', 1.5], ['per_page', 'abc'], ['page', 0],
            ['date_debut', '2026-02-30'], ['date_fin', 'hier'], ['actor_id', 0], ['object_id', 'abc'],
            ['action', ['nested']], ['object_type', str_repeat('x', 256)],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_audit_filters_return_422(string $field, mixed $value): void
    {
        $this->admin();
        $this->getJson('/api/parametrage/audit?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_reversed_date_range_is_rejected_and_pagination_boundaries_are_consistent(): void
    {
        $actor = $this->admin();
        $this->getJson('/api/parametrage/audit?date_debut=2026-10-22&date_fin=2026-10-21')->assertUnprocessable()->assertJsonValidationErrors('date_fin');
        foreach (['/api/parametrage/audit', '/api/users/'.$actor->id.'/organisation-history'] as $path) {
            $this->getJson($path.'?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
            $this->getJson($path.'?per_page=0')->assertUnprocessable();
            $this->getJson($path.'?per_page=101')->assertUnprocessable();
            $this->getJson($path.'?page=0')->assertUnprocessable();
        }
    }

    public function test_unknown_audit_ids_and_invalid_id_formats_return_404(): void
    {
        $this->admin();
        foreach (['administration:'.PHP_INT_MAX, 'maintenance:'.PHP_INT_MAX, '1', 'unknown:1', 'maintenance:0', 'administration:abc'] as $id) {
            $this->getJson('/api/parametrage/audit/'.$id)->assertNotFound();
        }
    }

    public function test_projection_excludes_secrets_unknown_keys_and_malformed_nested_values(): void
    {
        $actor = $this->admin();
        $secret = 'secret-de-test-a-exclure';
        $payload = ['nom' => 'Conservé', 'password' => $secret, 'temporary_password' => $secret, 'token' => $secret, 'hash' => $secret, 'credentials' => ['api_key' => $secret], 'prenom' => ['password' => $secret], 'role_ids' => [['token' => $secret]]];
        $id = $this->audit($actor, ['before_values' => json_encode($payload), 'after_values' => json_encode($payload)]);
        $stored = DB::table('organisation_audits')->find($id)->after_values;
        $detail = $this->getJson('/api/parametrage/audit/administration:'.$id)->assertOk()->assertJsonPath('data.after_values.nom', 'Conservé')->assertJsonMissingPath('data.after_values.password')->assertJsonMissingPath('data.after_values.prenom')->assertJsonMissingPath('data.after_values.role_ids');
        $this->assertStringNotContainsString($secret, $detail->getContent());
        $list = $this->getJson('/api/parametrage/audit?object_type=user&object_id='.$actor->id)->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $maintenance = $this->maintenance($actor, ['after_values' => json_encode($payload)]);
        $response = $this->getJson('/api/parametrage/audit/maintenance:'.$maintenance)->assertOk();
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->history($actor, ['after_values' => json_encode(['direction_id' => null, 'password' => $secret, 'direction' => ['id' => 1, 'nom' => 'Test', 'code' => 'TEST', 'api_key' => $secret], 'service' => ['nom' => ['token' => $secret]]])]);
        $history = $this->getJson('/api/users/'.$actor->id.'/organisation-history')->assertOk();
        $this->assertStringNotContainsString($secret, $history->getContent());
        $this->assertSame($stored, DB::table('organisation_audits')->find($id)->after_values);
    }

    public function test_unknown_legacy_metadata_is_not_fabricated_and_refusals_are_identifiable(): void
    {
        $actor = $this->admin();
        $unknown = $this->audit($actor, ['action' => 'ancien_evenement', 'object_type' => 'inconnu', 'after_values' => json_encode(['token' => 'non exposé'])]);
        $this->getJson('/api/parametrage/audit/administration:'.$unknown)->assertOk()->assertJsonPath('data.resultat', null)->assertJsonPath('data.schema_version', 1)->assertJsonPath('data.after_values', []);
        $refused = $this->audit($actor, ['action' => 'refus', 'after_values' => json_encode(['operation' => 'update_user', 'current_password' => 'non exposé'])]);
        $this->getJson('/api/parametrage/audit/administration:'.$refused)->assertOk()->assertJsonPath('data.resultat', 'REFUSE')->assertJsonPath('data.after_values.operation', 'update_user')->assertJsonMissingPath('data.after_values.current_password');
        $maintenance = $this->maintenance($actor, ['origine' => 'ancienne_origine_non_documentee']);
        $this->getJson('/api/parametrage/audit/maintenance:'.$maintenance)->assertOk()->assertJsonPath('data.resultat', null)->assertJsonPath('data.action', null);
    }

    public function test_history_and_audit_permissions_are_not_interchangeable(): void
    {
        $actor = $this->admin();
        $role = Role::where('system_key', 'super_admin')->firstOrFail();
        $role->revokePermissionTo('read_user');
        $this->getJson('/api/users/'.$actor->id.'/organisation-history')->assertForbidden();
        $this->getJson('/api/parametrage/audit')->assertOk();
        $role->givePermissionTo('read_user');
        $role->revokePermissionTo('read_parametrage_audit');
        $this->getJson('/api/users/'.$actor->id.'/organisation-history')->assertOk();
        $this->getJson('/api/parametrage/audit')->assertForbidden();
    }

    public function test_deleted_targets_leave_their_previous_audit_events_consultable(): void
    {
        $actor = $this->admin();
        $role = Role::factory()->create();
        $id = $this->audit($actor, ['object_type' => 'role', 'object_id' => $role->id, 'before_values' => null, 'after_values' => json_encode(['name' => $role->name])]);
        $this->delete('/api/roles/'.$role->id, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => rawurlencode('Suppression du rôle de test')])->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->getJson('/api/parametrage/audit/administration:'.$id)->assertOk()->assertJsonPath('data.after_values.name', $role->name)->assertJsonPath('data.object_id', $role->id);
    }

    public function test_views_never_mutate_audit_history_or_maintenance_and_support_head(): void
    {
        $actor = $this->admin();
        $audit = $this->audit($actor);
        $this->history($actor);
        $this->maintenance($actor);
        $before = $this->journalRows();
        foreach (['/api/parametrage/audit', '/api/parametrage/audit/administration:'.$audit, '/api/users/'.$actor->id.'/organisation-history'] as $path) {
            $this->getJson($path)->assertOk();
            $this->json('HEAD', $path)->assertOk()->assertContent('');
        }
        $this->assertSame($before, $this->journalRows());
    }

    public function test_no_ordinary_write_route_can_modify_or_delete_recorded_events(): void
    {
        $actor = $this->admin();
        $audit = $this->audit($actor);
        $history = $this->history($actor);
        $maintenance = $this->maintenance($actor);
        $before = $this->journalRows();
        foreach (['/api/parametrage/audit', '/api/parametrage/audit/administration:'.$audit, '/api/parametrage/audit/maintenance:'.$maintenance, '/api/users/'.$actor->id.'/organisation-history'] as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->json($method, $path, ['before_values' => [], 'after_values' => [], 'motif' => 'Tentative test'])->assertStatus(405);
            }
        }
        $this->assertSame($before, $this->journalRows());
        $this->json('PUT', '/api/users/'.$actor->id, ['nom' => 'Correction autorisée', 'version_attendue' => 0, 'organisation_audits' => [], 'user_organisation_histories' => [], 'parametrage_maintenance_audits' => [], 'before_values' => [], 'after_values' => []], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('organisation_audits');
        $this->assertSame($before['history'], DB::table('user_organisation_histories')->orderBy('id')->get()->toJson());
        $this->assertSame($before['maintenance'], DB::table('parametrage_maintenance_audits')->orderBy('id')->get()->toJson());
        foreach (json_decode($before['audit'], true) as $event) {
            $this->assertSame($event, (array) DB::table('organisation_audits')->find($event['id']));
        }
        $this->assertDatabaseHas('user_organisation_histories', ['id' => $history]);
    }

    /** @return array{audit: string, history: string, maintenance: string} */
    private function journalRows(): array
    {
        return [
            'audit' => DB::table('organisation_audits')->orderBy('id')->get()->toJson(),
            'history' => DB::table('user_organisation_histories')->orderBy('id')->get()->toJson(),
            'maintenance' => DB::table('parametrage_maintenance_audits')->orderBy('id')->get()->toJson(),
        ];
    }

    public function test_new_routes_and_resource_shapes_match_the_contract(): void
    {
        $actor = $this->admin();
        $audit = $this->audit($actor);
        $this->history($actor);
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        foreach ([
            ['/api/parametrage/audit', '/api/parametrage/audit', 'ParamAuditListResponse', true],
            ['/api/parametrage/audit/administration:'.$audit, '/api/parametrage/audit/{id}', 'ParamAuditResponse', false],
            ['/api/users/'.$actor->id.'/organisation-history', '/api/users/{id}/organisation-history', 'ParamOrganisationEventListResponse', true],
        ] as [$uri, $template, $responseSchema, $list]) {
            $response = $this->getJson($uri)->assertOk();
            $operation = $contract['paths'][$template]['get'];
            $this->assertSame('implemented', $operation['x-implementation-status']);
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            foreach (['200', '401', '403'] as $status) {
                $this->assertArrayHasKey($status, $operation['responses']);
            }
            if (! $list) {
                $this->assertArrayHasKey('404', $operation['responses']);
            }
            if ($list) {
                $this->assertArrayHasKey('422', $operation['responses']);
            }
            $schema = $contract['components']['schemas'][$responseSchema];
            $schemaRef = $list ? $schema['properties']['data']['items']['$ref'] : $schema['properties']['data']['$ref'];
            $fields = array_keys($contract['components']['schemas'][basename($schemaRef)]['properties']);
            sort($fields);
            $actual = array_keys($list ? $response->json('data.0') : $response->json('data'));
            sort($actual);
            $this->assertSame($fields, $actual);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }
}
