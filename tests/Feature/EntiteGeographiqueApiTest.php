<?php

namespace Tests\Feature;

use App\Models\DemandeModification;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EntiteGeographiqueApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_crud_accepts_optional_coordinates_and_description(): void
    {
        $user = User::factory()->create();
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $user->givePermissionTo(Permission::factory()->create(['name' => $action.'_entite_geographique']));
        }
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/entites-geographiques', [])->assertUnprocessable()->assertJsonValidationErrors('adresse');
        $response = $this->withToken($token)->postJson('/api/entites-geographiques', ['adresse' => 'Terrain derrière le marché'])->assertCreated()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null)->assertJsonPath('data.description', null);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('entites_geographiques', ['id' => $id, 'latitude' => null]);
        $this->withToken($token)->getJson('/api/entites-geographiques')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson("/api/entites-geographiques/{$id}")->assertOk();
        $this->withToken($token)->putJson("/api/entites-geographiques/{$id}", ['latitude' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->withToken($token)->putJson("/api/entites-geographiques/{$id}", ['adresse' => 'Autre adresse', 'latitude' => 0.4, 'longitude' => 9.4, 'description' => 'Test'])->assertOk();
        $this->assertDatabaseHas('entites_geographiques', ['id' => $id, 'adresse' => 'Autre adresse']);
        $this->withToken($token)->getJson('/api/entites-geographiques/999999')->assertNotFound();
        $this->withToken($token)->deleteJson("/api/entites-geographiques/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('entites_geographiques', ['id' => $id]);
    }

    public function test_access_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/entites-geographiques')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/entites-geographiques')->assertForbidden();
    }

    public function test_delete_returns_409_for_an_entity_used_in_modification_destinations(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::factory()->create(['name' => 'delete_entite_geographique']));
        $entite = EntiteGeographique::factory()->create();
        DemandeModification::factory()->create(['destinations' => [['entite_geographique_id' => $entite->id, 'ordre' => 1]]]);

        $this->withToken($user->createToken('test')->plainTextToken)->deleteJson("/api/entites-geographiques/{$entite->id}")->assertConflict();
        $this->assertModelExists($entite);
    }
}
