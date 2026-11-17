<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ParametrageIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private ?array $contract = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Integration exclusivement sur mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Base interdite.');
        }

        return $app;
    }

    /** @return array{User, string} */
    private function admin(): array
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $actor = User::factory()->create(['password' => Hash::make('Admin123!')]);
        $actor->assignRole(Role::where('system_key', 'super_admin')->firstOrFail());
        $token = $actor->createToken('integration')->plainTextToken;
        $this->token($token);

        return [$actor, $token];
    }

    private function token(?string $token): void
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        if ($token !== null) {
            $this->withToken($token);
        }
    }

    private function specification(): array
    {
        return $this->contract ??= Yaml::parseFile(base_path('openapi.yaml'));
    }

    /** @param array<string, mixed> $payload */
    private function exchange(string $method, string $uri, array $payload = [], int $status = 200, ?string $key = null): TestResponse
    {
        $route = app('router')->getRoutes()->match(Request::create($uri, $method));
        $contractOperation = $this->specification()['paths']['/'.$route->uri()][strtolower($method)];
        $operation = $contractOperation['x-current-operation'] ?? $contractOperation;
        $headers = [];
        foreach ([...($contractOperation['parameters'] ?? []), ...($operation['parameters'] ?? [])] as $parameter) {
            if ($parameter['in'] === 'header' && $parameter['name'] === 'Idempotency-Key') {
                $headers['Idempotency-Key'] = $key ?? (string) Str::uuid();
            }
        }
        if ($status < 400 && isset($operation['requestBody'])) {
            $this->schema($operation['requestBody']['content']['application/json']['schema'], json_decode(json_encode((object) $payload, JSON_THROW_ON_ERROR)));
        }
        app('auth')->forgetGuards();
        $response = $this->json($method, $uri, $payload, $headers)->assertStatus($status);
        $responseOperation = isset($operation['responses'][(string) $status]) ? $operation : $contractOperation;
        $this->assertArrayHasKey((string) $status, $responseOperation['responses']);
        $responseDefinition = $responseOperation['responses'][(string) $status];
        $resolvedResponse = $this->resolve($responseDefinition);
        if (! isset($resolvedResponse['content']) && isset($contractOperation['responses'][(string) $status])) {
            $responseDefinition = $contractOperation['responses'][(string) $status];
            $resolvedResponse = $this->resolve($responseDefinition);
        }
        if ($status !== 204) {
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $this->assertArrayHasKey('application/json', $resolvedResponse['content'] ?? []);
            $this->schema($resolvedResponse['content']['application/json']['schema'], json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
        }

        return $response;
    }

    private function resolve(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            $schema = $this->specification();
            foreach (explode('/', substr($ref, 2)) as $part) {
                $schema = $schema[$part];
            }
        }

        return $schema;
    }

    private function schema(array $schema, mixed $value): void
    {
        $schema = $this->resolve($schema);
        if ($value === null && ($schema['nullable'] ?? false)) {
            $this->assertNull($value);

            return;
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum']);
        }
        switch ($schema['type']) {
            case 'object':
                $this->assertInstanceOf(stdClass::class, $value);
                $values = get_object_vars($value);
                foreach ($schema['required'] ?? [] as $field) {
                    $this->assertArrayHasKey($field, $values, 'Réponse absente du champ '.$field.' ; champs reçus : '.implode(', ', array_keys($values)));
                }
                if (($schema['additionalProperties'] ?? true) === false) {
                    $this->assertSame([], array_diff(array_keys($values), array_keys($schema['properties'] ?? [])));
                }
                foreach ($values as $field => $item) {
                    if (isset($schema['properties'][$field])) {
                        $this->schema($schema['properties'][$field], $item);
                    } elseif (is_array($schema['additionalProperties'] ?? null)) {
                        $this->schema($schema['additionalProperties'], $item);
                    }
                }
                break;
            case 'array':
                $this->assertIsArray($value);
                $this->assertTrue(array_is_list($value));
                foreach ($value as $item) {
                    $this->schema($schema['items'], $item);
                }
                if (isset($schema['minItems'])) {
                    $this->assertGreaterThanOrEqual($schema['minItems'], count($value));
                }
                if ($schema['uniqueItems'] ?? false) {
                    $this->assertSame(count($value), count(array_unique($value, SORT_REGULAR)));
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
            case 'boolean':
                $this->assertIsBool($value);
                break;
            case 'string':
                $this->assertIsString($value);
                if (isset($schema['minLength'])) {
                    $this->assertGreaterThanOrEqual($schema['minLength'], mb_strlen($value));
                }
                if (isset($schema['maxLength'])) {
                    $this->assertLessThanOrEqual($schema['maxLength'], mb_strlen($value));
                }
                if (isset($schema['pattern'])) {
                    $this->assertMatchesRegularExpression('~'.$schema['pattern'].'~u', $value);
                }
                if (($schema['format'] ?? null) === 'uuid') {
                    $this->assertTrue(Str::isUuid($value));
                }
                if (($schema['format'] ?? null) === 'date-time') {
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value);
                }
                break;
            default:
                $this->fail('Type non couvert dans ce parcours : '.$schema['type']);
        }
    }

    public function test_complete_http_journey_links_organisation_rbac_credentials_and_immutable_journals(): void
    {
        [$admin, $adminToken] = $this->admin();
        $a = $this->exchange('POST', '/api/directions', ['nom' => 'Direction integration A', 'code' => 'IT_A_'.Str::upper(Str::random(10))], 201)->json('data');
        $b = $this->exchange('POST', '/api/directions', ['nom' => 'Direction integration B', 'code' => 'IT_B_'.Str::upper(Str::random(10))], 201)->json('data');
        $a = $this->exchange('PUT', '/api/directions/'.$a['id'], ['nom' => 'Nom avant transfert', 'version_attendue' => $a['lock_version']])->json('data');
        $sa = $this->exchange('POST', '/api/services', ['direction_id' => $a['id'], 'nom' => 'Service A', 'code' => 'IT_SERVICE'], 201)->json('data');
        $sb = $this->exchange('POST', '/api/services', ['direction_id' => $b['id'], 'nom' => 'Service B', 'code' => 'IT_SERVICE'], 201)->json('data');
        $this->exchange('PUT', '/api/services/'.$sa['id'], ['description' => 'Service corrige', 'version_attendue' => $sa['lock_version']]);
        $email = 'integration-'.Str::lower(Str::random(12)).'@example.test';
        $user = $this->exchange('POST', '/api/users', ['nom' => 'Integration', 'prenom' => 'Agent', 'email' => $email, 'direction_id' => $a['id'], 'service_id' => $sa['id']], 201)->assertJsonMissingPath('data.temporary_password')->json('data');
        $this->assertTrue(User::findOrFail($user['id'])->roles()->where('system_key', 'demandeur')->exists());
        $role = $this->exchange('POST', '/api/roles', ['nom' => 'IT_ROLE_'.Str::random(12)], 201)->json('data');
        $permissionIds = Permission::whereIn('name', ['read_direction', 'read_demande'])->orderBy('id')->pluck('id')->all();
        $role = $this->exchange('PUT', '/api/roles/'.$role['id'].'/permissions', ['permission_ids' => $permissionIds, 'version_attendue' => $role['lock_version'], 'motif' => 'Droits integration'])->json('data');
        $user = $this->exchange('PUT', '/api/users/'.$user['id'].'/roles', ['role_ids' => [$role['id']], 'version_attendue' => $user['lock_version'], 'motif' => 'Role explicite integration'])->json('data');
        $user = $this->exchange('PUT', '/api/users/'.$user['id'], ['prenom' => 'Agent corrige', 'direction_id' => $b['id'], 'service_id' => $sb['id'], 'version_attendue' => $user['lock_version'], 'motif' => 'Transfert organisationnel'])->json('data');
        $before = $this->exchange('GET', '/api/parametrage/audit?object_type=user&object_id='.$user['id'].'&per_page=100')->json('data');
        $a = $this->exchange('PUT', '/api/directions/'.$a['id'], ['nom' => 'Nom apres transfert', 'version_attendue' => $a['lock_version']])->json('data');
        $a = $this->exchange('PUT', '/api/directions/'.$a['id'].'/deactivate', ['version_attendue' => $a['lock_version'], 'motif' => 'Desactivation integration'])->assertJsonPath('data.statut', 'INACTIF')->json('data');
        $this->exchange('GET', '/api/services/'.$sa['id'])->assertJsonPath('data.statut', 'ACTIF');
        $this->exchange('PUT', '/api/directions/'.$a['id'].'/activate', ['version_attendue' => $a['lock_version'], 'motif' => 'Reactivation integration'])->assertJsonPath('data.statut', 'ACTIF');
        $reveal = ['version_attendue' => $user['lock_version'], 'password_generation' => $user['password_generation'], 'current_password' => 'Admin123!', 'motif' => 'Communication initiale'];
        $initial = $this->exchange('POST', '/api/users/'.$user['id'].'/temporary-password/reveal', $reveal)->json('data.temporary_password');
        $this->token(null);
        $temporaryToken = $this->exchange('POST', '/api/login', ['email' => $email, 'password' => $initial])->assertJsonPath('data.must_change_password', true)->json('data.token');
        $this->token($temporaryToken);
        $this->exchange('GET', '/api/me')->assertJsonPath('data.service_id', $sb['id'])->assertJsonMissingPath('data.temporary_password');
        $this->exchange('GET', '/api/directions', status: 403);
        $userToken = $this->exchange('PUT', '/api/me/password', ['new_password' => 'Agent123!', 'new_password_confirmation' => 'Agent123!'])->assertJsonPath('data.must_change_password', false)->json('data.token');
        $this->token($temporaryToken);
        $this->exchange('GET', '/api/me', status: 401);
        $this->token($userToken);
        $this->exchange('PUT', '/api/me/password', ['new_password' => 'Agent234!', 'new_password_confirmation' => 'Agent234!'], 422)->assertJsonValidationErrors('current_password');
        $this->exchange('PUT', '/api/me/password', ['current_password' => 'Erreur123!', 'new_password' => 'Agent234!', 'new_password_confirmation' => 'Agent234!'], 401);
        $this->exchange('GET', '/api/me')->assertJsonPath('data.must_change_password', false);
        $previousToken = $userToken;
        $userToken = $this->exchange('PUT', '/api/me/password', ['current_password' => 'Agent123!', 'new_password' => 'Agent234!', 'new_password_confirmation' => 'Agent234!'])->json('data.token');
        $this->token($previousToken);
        $this->exchange('GET', '/api/me', status: 401);
        $this->token($userToken);
        $this->exchange('GET', '/api/directions?search='.$b['code'])->assertJsonPath('data.0.id', $b['id']);
        $this->exchange('GET', '/api/demandes');
        $this->token($adminToken);
        $role = $this->exchange('PUT', '/api/roles/'.$role['id'], ['description' => 'Role suspendu', 'statut' => 'INACTIF', 'version_attendue' => $role['lock_version'], 'motif' => 'Suspension integration'])->json('data');
        $this->token($userToken);
        $this->exchange('GET', '/api/me')->assertJsonPath('data.permissions_effectives', []);
        $this->exchange('GET', '/api/directions', status: 403);
        $this->exchange('GET', '/api/demandes', status: 403);
        $this->token($adminToken);
        $this->exchange('PUT', '/api/roles/'.$role['id'], ['statut' => 'ACTIF', 'version_attendue' => $role['lock_version'], 'motif' => 'Reactivation integration']);
        $this->token($userToken);
        $this->exchange('GET', '/api/directions?search='.$b['code'])->assertJsonPath('data.0.id', $b['id']);
        $this->exchange('GET', '/api/demandes');
        $this->token($adminToken);
        $user = $this->exchange('GET', '/api/users/'.$user['id'])->json('data');
        $resetPayload = ['version_attendue' => $user['lock_version'], 'actor_password' => 'Admin123!', 'motif' => 'Reset integration'];
        $key = (string) Str::uuid();
        $reset = $this->exchange('POST', '/api/users/'.$user['id'].'/reset-password', $resetPayload, key: $key)->assertJsonMissingPath('data.temporary_password');
        $this->exchange('POST', '/api/users/'.$user['id'].'/reset-password', $resetPayload, key: $key)->assertExactJson($reset->json());
        $user = [...$user, ...$reset->json('data')];
        $this->token($userToken);
        $this->exchange('GET', '/api/me', status: 401);
        $this->token(null);
        $this->exchange('POST', '/api/login', ['email' => $email, 'password' => 'Agent234!'], 401);
        $this->token($adminToken);
        $second = $this->exchange('POST', '/api/users/'.$user['id'].'/temporary-password/reveal', [...$reveal, 'version_attendue' => $user['lock_version'], 'password_generation' => $user['password_generation']])->json('data.temporary_password');
        $this->token(null);
        $temporaryToken = $this->exchange('POST', '/api/login', ['email' => $email, 'password' => $second])->json('data.token');
        $this->token($temporaryToken);
        $finalToken = $this->exchange('PUT', '/api/me/password', ['new_password' => 'Agent456!', 'new_password_confirmation' => 'Agent456!'])->json('data.token');
        $this->token($adminToken);
        $user = $this->exchange('GET', '/api/users/'.$user['id'])->assertJsonPath('data.temporary_password_available', false)->json('data');
        $this->exchange('POST', '/api/users/'.$user['id'].'/temporary-password/reveal', [...$reveal, 'version_attendue' => $user['lock_version'], 'password_generation' => $user['password_generation']], 409);
        $history = $this->exchange('GET', '/api/users/'.$user['id'].'/organisation-history')->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame($a['id'], $history[0]['after_values']['direction_id']);
        $this->assertSame('Nom avant transfert', $history[0]['after_values']['direction']['nom']);
        $this->assertSame($b['id'], $history[1]['after_values']['direction_id']);
        $this->assertSame($sb['id'], $history[1]['after_values']['service_id']);
        foreach ($before as $event) {
            $this->exchange('GET', '/api/parametrage/audit/'.$event['id'])->assertJsonPath('data', $event);
        }
        $persisted = DB::table('organisation_commands')->where('actor_id', $admin->id)->get()->toJson().DB::table('organisation_audits')->where('actor_id', $admin->id)->get()->toJson();
        foreach ([$initial, $second, 'Admin123!', 'Agent123!', 'Agent234!'] as $secret) {
            $this->assertStringNotContainsString($secret, $persisted);
        }
        $this->token($finalToken);
        $this->exchange('POST', '/api/logout');
        $this->exchange('GET', '/api/me', status: 401);
    }

    public function test_role_and_permission_lifecycle_matches_target_contract_schemas(): void
    {
        $this->admin();

        $role = $this->exchange('POST', '/api/roles', [
            'nom' => 'IT_ROLE_'.Str::upper(Str::random(12)),
            'description' => 'Role de verification du contrat',
        ], 201)->json('data');
        $permission = $this->exchange('POST', '/api/permissions', [
            'code' => 'read_test_'.Str::lower(Str::random(12)),
            'libelle' => 'Permission de verification',
            'description' => 'Permission personnalisee de test',
        ], 201)->json('data');

        $role = $this->exchange('PUT', '/api/roles/'.$role['id'], [
            'description' => 'Role modifie',
            'version_attendue' => $role['lock_version'],
        ])->json('data');
        $permission = $this->exchange('PUT', '/api/permissions/'.$permission['id'], [
            'libelle' => 'Permission modifiee',
            'version_attendue' => $permission['lock_version'],
        ])->json('data');
        $role = $this->exchange('PUT', '/api/roles/'.$role['id'].'/permissions', [
            'permission_ids' => [$permission['id']],
            'version_attendue' => $role['lock_version'],
            'motif' => 'Attribution pour verification du contrat',
        ])->json('data');

        $this->exchange('GET', '/api/roles/'.$role['id'])->assertJsonPath('data.permissions.0.id', $permission['id']);
        $this->exchange('GET', '/api/permissions/'.$permission['id'])->assertJsonPath('data.code', $permission['code']);
        $this->exchange('GET', '/api/roles?search='.$role['nom'].'&per_page=1')->assertJsonPath('meta.per_page', 1);
        $this->exchange('GET', '/api/permissions?search='.$permission['code'].'&per_page=1')->assertJsonPath('meta.per_page', 1);
    }

    public function test_two_administrators_cannot_sequentially_remove_the_last_remaining_one(): void
    {
        [$first, $firstToken] = $this->admin();
        $super = Role::where('system_key', 'super_admin')->firstOrFail();
        $second = User::factory()->create();
        $second->assignRole($super);
        $secondToken = $second->createToken('integration')->plainTextToken;
        foreach ($super->users()->whereNotIn('users.id', [$first->id, $second->id])->get() as $legacy) {
            $legacy->update(['statut' => 'INACTIF']);
        }
        $this->exchange('PATCH', '/api/users/'.$first->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Autre administrateur disponible']);
        $this->token($firstToken);
        $this->exchange('GET', '/api/me', status: 401);
        $this->token($secondToken);
        $this->exchange('PATCH', '/api/users/'.$second->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Dernier administrateur'], 409)->assertJsonPath('code', 'LAST_EFFECTIVE_ADMIN');
        $this->exchange('PUT', '/api/users/'.$second->id.'/roles', ['role_ids' => [], 'version_attendue' => 0, 'motif' => 'Retrait du dernier administrateur'], 409)->assertJsonPath('code', 'LAST_EFFECTIVE_ADMIN');
        $this->assertSame('string', $this->specification()['components']['schemas']['ParamError']['properties']['code']['type']);
        $this->exchange('GET', '/api/me')->assertJsonPath('data.statut', 'ACTIF');
        $this->assertTrue($second->fresh()->hasRole('super_admin'));
    }

    public function test_idempotency_namespace_is_shared_between_the_organisation_and_user_lots(): void
    {
        $this->admin();
        $key = (string) Str::uuid();
        $direction = $this->exchange('POST', '/api/directions', ['nom' => 'Integration cle partagee', 'code' => 'IT_KEY_'.Str::random(10)], 201, $key)->json('data');
        $email = 'key-'.Str::random(10).'@example.test';
        $this->exchange('POST', '/api/users', ['nom' => 'Test', 'prenom' => 'Cle', 'email' => $email, 'direction_id' => $direction['id']], 409, $key)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertSame(1, DB::table('organisation_commands')->where('command_key', $key)->count());
    }

    public static function stringBoundaries(): array
    {
        return [
            ['CreateRoleRequest', 'POST', 'roles', 'nom'], ['UpdateRoleRequest', 'PUT', 'roles', 'nom'],
            ['CreatePermissionRequest', 'POST', 'permissions', 'code'], ['CreatePermissionRequest', 'POST', 'permissions', 'libelle'],
            ['UpdatePermissionRequest', 'PUT', 'permissions', 'code'], ['UpdatePermissionRequest', 'PUT', 'permissions', 'libelle'],
            ['CreateUserRequest', 'POST', 'users', 'nom'], ['CreateUserRequest', 'POST', 'users', 'prenom'],
            ['UpdateUserRequest', 'PUT', 'users', 'nom'], ['UpdateUserRequest', 'PUT', 'users', 'prenom'],
        ];
    }

    #[DataProvider('stringBoundaries')]
    public function test_contract_documents_the_observed_required_string_boundaries(string $schema, string $method, string $resource, string $field): void
    {
        $this->admin();
        $path = '/api/'.$resource;
        $payload = match ($resource) {
            'roles' => ['nom' => 'IT_'.Str::random(12)],
            'permissions' => ['code' => 'IT_'.Str::random(12), 'libelle' => 'Libelle test'],
            'users' => ['nom' => 'Test', 'prenom' => 'Integration', 'email' => 'boundary-'.Str::random(10).'@example.test', 'direction_id' => Direction::factory()->create()->id],
        };
        if ($method === 'PUT') {
            $model = match ($resource) {
                'roles' => Role::factory()->create(), 'permissions' => Permission::factory()->create(), 'users' => User::factory()->create()
            };
            $path .= '/'.$model->id;
            $payload['version_attendue'] = 0;
            if ($resource === 'users') {
                unset($payload['direction_id']);
            }
        }
        $this->exchange($method, $path, [...$payload, $field => str_repeat('x', 256)], 422)->assertJsonValidationErrors($field);
        $this->exchange($method, $path, [...$payload, $field => ''], 422)->assertJsonValidationErrors($field);
        $property = $this->specification()['components']['schemas'][$schema]['properties'][$field];
        $this->assertSame(255, $property['maxLength'] ?? null, 'Le backend refuse deja 256 caracteres ; la borne doit etre documentee.');
        $this->assertSame(1, $property['minLength'] ?? null, 'Le backend refuse deja la chaine vide ; la borne doit etre documentee.');
    }

    public function test_role_creation_with_null_status_uses_the_existing_active_default(): void
    {
        $this->admin();
        $response = $this->postJson('/api/roles', ['nom' => 'IT_NULL_'.Str::random(12), 'statut' => null], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.statut', 'ACTIF');
        $property = $this->specification()['components']['schemas']['CreateRoleRequest']['properties']['statut'];
        $this->assertTrue($property['nullable'] ?? false, 'Le Form Request accepte null et le controleur applique ACTIF.');
        $this->assertSame('ACTIF', $property['default'] ?? null);
    }

    public static function deletableResources(): array
    {
        return [['roles'], ['permissions']];
    }

    #[DataProvider('deletableResources')]
    public function test_delete_reason_limit_applies_to_decoded_utf8_not_the_encoded_header(string $resource): void
    {
        $this->admin();
        $target = $resource === 'roles' ? Role::factory()->create() : Permission::factory()->create();
        $reason = str_repeat('é', 2000);
        app('auth')->forgetGuards();
        $this->delete('/api/'.$resource.'/'.$target->id, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => rawurlencode($reason)])->assertNoContent();
        $kind = $resource === 'roles' ? 'role' : 'permission';
        $this->assertDatabaseHas('organisation_audits', ['object_type' => $kind, 'object_id' => $target->id, 'action' => 'delete_'.$kind, 'motif' => $reason]);
        $other = $resource === 'roles' ? Role::factory()->create() : Permission::factory()->create();
        app('auth')->forgetGuards();
        $this->delete('/api/'.$resource.'/'.$other->id, [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => '0', 'X-Change-Reason' => rawurlencode($reason.'é')])->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->assertDatabaseHas($resource, ['id' => $other->id]);
        $parameters = array_column($this->specification()['paths']['/api/'.$resource.'/{id}']['delete']['parameters'], null, 'name');
        $this->assertArrayNotHasKey('maxLength', $parameters['X-Change-Reason']['schema'], 'Le backend limite le texte decode, pas la longueur brute apres encodage UTF-8.');
        $this->assertMatchesRegularExpression('/2\s?000/u', $parameters['X-Change-Reason']['description']);
    }

    public function test_parametrage_operation_permissions_and_write_controls_match_real_routes(): void
    {
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            $permission = $route->defaults['organisation_permission'] ?? $route->defaults['journal_permission'] ?? null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'parametrage:')) {
                    $permission = explode(',', substr($middleware, strlen('parametrage:')))[0];
                }
            }
            if ($permission === null) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operation = $this->specification()['paths']['/'.$route->uri()][strtolower($method)];
                $this->assertContains($permission, $operation['x-permissions'] ?? [$operation['x-permission'] ?? null]);
                $this->assertSame([['bearerAuth' => []]], $operation['security']);
                if (($route->defaults['organisation_admin'] ?? null) === false) {
                    $this->assertArrayNotHasKey('x-authorized-roles', $operation, 'Le GET operationnel est permissionne sans restriction supplementaire a des noms de roles.');
                    $this->assertArrayNotHasKey('x-required-system-role', $operation);
                }
                foreach (['401', '403'] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                }
                if (! in_array($method, ['GET', 'HEAD'], true) && ! str_ends_with($route->uri(), '/reveal')) {
                    $headers = array_column(array_filter($operation['parameters'] ?? [], fn (array $parameter): bool => $parameter['in'] === 'header'), null, 'name');
                    $this->assertTrue($headers['Idempotency-Key']['required']);
                    $this->assertArrayHasKey('409', $operation['responses']);
                    $this->assertArrayHasKey('422', $operation['responses']);
                    if ($method === 'DELETE') {
                        $this->assertArrayNotHasKey('requestBody', $operation);
                        $this->assertTrue($headers['X-Expected-Version']['required']);
                        $this->assertTrue($headers['X-Change-Reason']['required']);
                    } elseif (in_array('id', $route->parameterNames(), true)) {
                        $body = $this->resolve($operation['requestBody']['content']['application/json']['schema']);
                        $this->assertContains('version_attendue', $body['required']);
                    }
                }
                $checked++;
            }
        }
        $this->assertSame(51, $checked);
    }
}
