<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\DemandeModification;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TypeSortie;
use App\Models\User;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReferenceCatalogParametrageTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Base de test interdite.');
        }

        return $app;
    }

    private function admin(): User
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create([
            'direction_id' => Direction::factory()->create()->id,
            'password' => Hash::make('AdminPass123!'),
            'must_change_password' => false,
            'statut' => 'ACTIF',
        ]);
        $user->assignRole(Role::query()->where('system_key', 'super_admin')->firstOrFail());
        $this->withToken($user->createToken('reference-catalog-tests')->plainTextToken);

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function command(string $method, string $uri, array $payload, ?string $key = null): TestResponse
    {
        return $this->json($method, $uri, $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function deleteReference(string $uri, int $version, string $reason, ?string $key = null): TestResponse
    {
        return $this->delete($uri, [], [
            'Accept' => 'application/json',
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
            'X-Expected-Version' => (string) $version,
            'X-Change-Reason' => rawurlencode($reason),
        ]);
    }

    public function test_type_sortie_creation_is_idempotent_and_conflicting_intent_is_rejected(): void
    {
        $this->admin();
        $key = (string) Str::uuid();
        $payload = ['code' => 'TYPE_'.Str::upper(Str::random(10)), 'libelle' => 'Véhicule administratif'];

        $first = $this->command('POST', '/api/types-sortie', $payload, $key)
            ->assertCreated()
            ->assertJsonPath('data.statut', 'ACTIF')
            ->assertJsonPath('data.lock_version', 0)
            ->assertJsonPath('data.utilise', false);
        $id = $first->json('data.id');
        $this->command('POST', '/api/types-sortie', $payload, $key)->assertCreated()->assertExactJson($first->json());
        $this->command('POST', '/api/types-sortie', [...$payload, 'libelle' => 'Autre intention'], $key)
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseHas('types_sortie', ['id' => $id, ...$payload, 'statut' => 'ACTIF', 'lock_version' => 0]);
        $this->assertSame(1, DB::table('organisation_commands')->where('command_key', $key)->count());
    }

    public function test_type_sortie_update_uses_version_and_rejects_undeclared_code_changes(): void
    {
        $this->admin();
        $type = TypeSortie::factory()->create();
        $key = (string) Str::uuid();
        $payload = ['libelle' => 'Libellé modifié', 'version_attendue' => 0, 'motif' => 'Correction administrative'];

        $updated = $this->command('PUT', '/api/types-sortie/'.$type->id, $payload, $key)
            ->assertOk()
            ->assertJsonPath('data.libelle', 'Libellé modifié')
            ->assertJsonPath('data.lock_version', 1);
        $this->command('PUT', '/api/types-sortie/'.$type->id, $payload, $key)->assertOk()->assertExactJson($updated->json());
        $this->command('PUT', '/api/types-sortie/'.$type->id, ['libelle' => 'Écrasement', 'version_attendue' => 0], (string) Str::uuid())
            ->assertConflict()
            ->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->command('PUT', '/api/types-sortie/'.$type->id, ['code' => 'CODE_MODIFIE', 'version_attendue' => 1], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertDatabaseHas('types_sortie', ['id' => $type->id, 'code' => $type->code, 'libelle' => 'Libellé modifié', 'lock_version' => 1]);
    }

    public function test_unknown_fields_are_rejected_even_when_an_update_idempotency_key_would_replay(): void
    {
        $this->admin();
        $type = TypeSortie::factory()->create();
        $key = (string) Str::uuid();
        $payload = ['libelle' => 'Libellé valide', 'version_attendue' => 0, 'motif' => 'Correction'];
        $this->command('PUT', '/api/types-sortie/'.$type->id, $payload, $key)->assertOk();

        $this->command('PUT', '/api/types-sortie/'.$type->id, [...$payload, 'statut' => 'INACTIF'], $key)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');
        $this->assertDatabaseHas('types_sortie', ['id' => $type->id, 'libelle' => 'Libellé valide', 'statut' => 'ACTIF', 'lock_version' => 1]);
    }

    public function test_used_type_sortie_cannot_be_updated_or_deleted(): void
    {
        $this->admin();
        $type = TypeSortie::factory()->create();
        Demande::factory()->create(['type_sortie_id' => $type->id]);

        $this->command('PUT', '/api/types-sortie/'.$type->id, ['libelle' => 'Interdit', 'version_attendue' => 0], (string) Str::uuid())
            ->assertConflict()
            ->assertJsonPath('code', 'REFERENCE_IN_USE');
        $this->deleteReference('/api/types-sortie/'.$type->id, 0, 'Suppression de test')
            ->assertConflict()
            ->assertJsonPath('code', 'REFERENCE_IN_USE');

        $this->assertModelExists($type);
        $this->assertDatabaseHas('types_sortie', ['id' => $type->id, 'libelle' => $type->libelle]);
    }

    public function test_geographic_coordinates_follow_openapi_bounds_and_precision(): void
    {
        $this->admin();
        $this->command('POST', '/api/entites-geographiques', ['adresse' => 'Adresse de test', 'latitude' => 90.0000001], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');
        $this->command('POST', '/api/entites-geographiques', ['adresse' => 'Adresse de test', 'longitude' => 9.12345678], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');

        $response = $this->command('POST', '/api/entites-geographiques', [
            'adresse' => 'Adresse valide',
            'latitude' => -90,
            'longitude' => 180,
            'description' => null,
        ])->assertCreated()->assertJsonPath('data.statut', 'ACTIF')->assertJsonPath('data.lock_version', 0);

        $entity = EntiteGeographique::query()->findOrFail($response->json('data.id'));
        $this->assertDatabaseHas('entites_geographiques', ['id' => $entity->id, 'latitude' => '-90.0000000', 'longitude' => '180.0000000']);
    }

    public function test_geographic_coordinates_require_json_numbers_and_unknown_fields_precede_idempotency_replay(): void
    {
        $this->admin();
        $this->command('POST', '/api/entites-geographiques', ['adresse' => 'Coordonnée invalide', 'latitude' => '9.123'], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');
        $this->command('POST', '/api/entites-geographiques', ['adresse' => 'Coordonnée invalide', 'longitude' => '9.123'], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');

        $entity = EntiteGeographique::factory()->create();
        $key = (string) Str::uuid();
        $payload = ['adresse' => 'Adresse corrigée', 'version_attendue' => 0, 'motif' => 'Correction'];
        $this->command('PUT', '/api/entites-geographiques/'.$entity->id, ['latitude' => '9.123', 'version_attendue' => 0, 'motif' => 'Correction'], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');
        $this->command('PUT', '/api/entites-geographiques/'.$entity->id, ['longitude' => '9.123', 'version_attendue' => 0, 'motif' => 'Correction'], (string) Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');
        $this->command('PUT', '/api/entites-geographiques/'.$entity->id, $payload, $key)->assertOk();
        $this->command('PUT', '/api/entites-geographiques/'.$entity->id, [...$payload, 'statut' => 'INACTIF'], $key)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');

        $this->assertDatabaseHas('entites_geographiques', ['id' => $entity->id, 'adresse' => 'Adresse corrigée', 'statut' => 'ACTIF', 'lock_version' => 1]);
    }

    public function test_geographic_idempotency_fingerprint_includes_address_and_coordinates(): void
    {
        $this->admin();
        $key = (string) Str::uuid();
        $payload = ['adresse' => 'Point de départ A', 'latitude' => 0.3924, 'longitude' => 9.4536];
        $first = $this->command('POST', '/api/entites-geographiques', $payload, $key)->assertCreated();

        $this->command('POST', '/api/entites-geographiques', [...$payload, 'adresse' => 'Point de départ B'], $key)
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, EntiteGeographique::query()->where('adresse', 'Point de départ A')->count());
        $this->assertDatabaseHas('entites_geographiques', ['id' => $first->json('data.id'), 'adresse' => 'Point de départ A']);
    }

    public function test_used_geographic_entity_cannot_be_updated_or_deleted(): void
    {
        $this->admin();
        $entity = EntiteGeographique::factory()->create();
        DemandeModification::factory()->create(['adresse_depart_id' => $entity->id]);

        $this->command('PUT', '/api/entites-geographiques/'.$entity->id, ['adresse' => 'Modification interdite', 'version_attendue' => 0], (string) Str::uuid())
            ->assertConflict()
            ->assertJsonPath('code', 'REFERENCE_IN_USE');
        $this->deleteReference('/api/entites-geographiques/'.$entity->id, 0, 'Suppression de test')
            ->assertConflict()
            ->assertJsonPath('code', 'REFERENCE_IN_USE');

        $this->assertModelExists($entity);
        $this->assertDatabaseHas('entites_geographiques', ['id' => $entity->id, 'adresse' => $entity->adresse]);
    }

    public function test_reference_status_changes_are_versioned_and_unused_entries_can_be_deleted(): void
    {
        $this->admin();
        $type = TypeSortie::factory()->create();
        $entity = EntiteGeographique::factory()->create();

        $deactivatedType = $this->command('PUT', '/api/types-sortie/'.$type->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Fin d’utilisation'])->assertOk()->assertJsonPath('data.statut', 'INACTIF')->assertJsonPath('data.lock_version', 1);
        $this->getJson('/api/parametrage/types-sortie?statut=INACTIF&search='.$type->code)->assertOk()->assertJsonPath('data.0.id', $type->id);
        $this->command('PUT', '/api/types-sortie/'.$type->id.'/activate', ['version_attendue' => $deactivatedType->json('data.lock_version'), 'motif' => 'Réactivation'])->assertOk()->assertJsonPath('data.statut', 'ACTIF')->assertJsonPath('data.lock_version', 2);
        $this->deleteReference('/api/types-sortie/'.$type->id, 2, 'Suppression sans utilisation')->assertNoContent();
        $this->assertDatabaseMissing('types_sortie', ['id' => $type->id]);
        $this->assertSame(1, DB::table('organisation_audits')->where('object_type', 'type_sortie')->where('object_id', $type->id)->where('action', 'delete_type_sortie')->count());

        $deactivatedEntity = $this->command('PUT', '/api/entites-geographiques/'.$entity->id.'/deactivate', ['version_attendue' => 0, 'motif' => 'Fin d’utilisation'])->assertOk()->assertJsonPath('data.statut', 'INACTIF');
        $this->getJson('/api/parametrage/entites-geographiques?statut=INACTIF')->assertOk()->assertJsonFragment(['id' => $entity->id, 'statut' => 'INACTIF']);
        $this->command('PUT', '/api/entites-geographiques/'.$entity->id.'/activate', ['version_attendue' => $deactivatedEntity->json('data.lock_version'), 'motif' => 'Réactivation'])->assertOk()->assertJsonPath('data.statut', 'ACTIF');
    }

    public function test_permission_without_super_admin_role_does_not_allow_reference_creation(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
        $user->givePermissionTo(Permission::query()->where('name', 'create_type_sortie')->firstOrFail());
        $this->withToken($user->createToken('unprivileged-reference-tests')->plainTextToken);

        $this->command('POST', '/api/types-sortie', ['code' => 'FORBIDDEN_'.Str::upper(Str::random(8)), 'libelle' => 'Interdit'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(0, TypeSortie::query()->where('code', 'like', 'FORBIDDEN_%')->count());
    }

    public function test_operational_type_sortie_list_is_searched_paginated_and_active_only(): void
    {
        $this->admin();
        $first = TypeSortie::factory()->create(['code' => 'CATALOG_ALPHA', 'libelle' => 'Déplacement alpha', 'statut' => 'ACTIF']);
        TypeSortie::factory()->create(['code' => 'CATALOG_BETA', 'libelle' => 'Déplacement beta', 'statut' => 'ACTIF']);
        TypeSortie::factory()->create(['code' => 'CATALOG_DISABLED', 'libelle' => 'Déplacement désactivé', 'statut' => 'INACTIF']);

        $page = $this->getJson('/api/types-sortie?search=CATALOG&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.statut', 'ACTIF')
            ->assertJsonPath('data.0.utilise', false);
        $this->assertSame(['id', 'code', 'libelle', 'statut', 'utilise', 'lock_version'], array_keys($page->json('data.0')));

        $this->getJson('/api/types-sortie?search=CATALOG&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_operational_geographic_list_is_searched_paginated_and_active_only(): void
    {
        $this->admin();
        $first = EntiteGeographique::factory()->create(['adresse' => 'Catalogue Alpha', 'statut' => 'ACTIF']);
        EntiteGeographique::factory()->create(['adresse' => 'Catalogue Beta', 'statut' => 'ACTIF']);
        EntiteGeographique::factory()->create(['adresse' => 'Catalogue désactivé', 'statut' => 'INACTIF']);

        $page = $this->getJson('/api/entites-geographiques?search=Catalogue&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.statut', 'ACTIF')
            ->assertJsonPath('data.0.utilise', false);
        $this->assertSame(['id', 'adresse', 'latitude', 'longitude', 'description', 'statut', 'utilise', 'lock_version'], array_keys($page->json('data.0')));
    }

    public function test_operational_catalog_filters_enforce_the_documented_pagination_limit(): void
    {
        $this->admin();

        $this->getJson('/api/types-sortie?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/entites-geographiques?statut=INACTIF')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('statut');
    }

    public function test_reference_details_return_administrative_schema_and_require_super_admin(): void
    {
        $this->admin();
        $type = TypeSortie::factory()->create();
        $entity = EntiteGeographique::factory()->create();

        $this->getJson('/api/types-sortie/'.$type->id)
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['id', 'code', 'libelle', 'statut', 'utilise', 'lock_version']]);
        $this->getJson('/api/entites-geographiques/'.$entity->id)
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['id', 'adresse', 'latitude', 'longitude', 'description', 'statut', 'utilise', 'lock_version']]);

    }

    public function test_user_with_read_permission_but_without_super_admin_gets_403_for_reference_details(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $type = TypeSortie::factory()->create();
        $reader = User::factory()->create();
        $reader->givePermissionTo(Permission::query()->where('name', 'read_type_sortie')->firstOrFail());

        $this->withToken($reader->createToken('catalog-reader')->plainTextToken)
            ->getJson('/api/types-sortie/'.$type->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }
}
