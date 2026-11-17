<?php

namespace Tests\Feature;

use App\Models\DemandeModification;
use App\Models\EntiteGeographique;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class EntiteGeographiqueApiTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_crud_accepts_optional_coordinates_and_description(): void
    {
        $token = $this->administrativeToken();
        $response = $this->withToken($token)->postJson('/api/entites-geographiques', ['adresse' => 'Terrain derrière le marché'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null)->assertJsonPath('data.description', null);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('entites_geographiques', ['id' => $id, 'latitude' => null]);
        $this->withToken($token)->getJson('/api/entites-geographiques')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson("/api/entites-geographiques/{$id}")->assertOk();
        $createdEntity = EntiteGeographique::query()->findOrFail($id);
        $this->withToken($token)->putJson("/api/entites-geographiques/{$id}", ['version_attendue' => $createdEntity->lock_version, 'latitude' => 'abc'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->withToken($token)->putJson("/api/entites-geographiques/{$id}", ['version_attendue' => $createdEntity->fresh()->lock_version, 'adresse' => 'Autre adresse', 'latitude' => 0.4, 'longitude' => 9.4, 'description' => 'Test'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('entites_geographiques', ['id' => $id, 'adresse' => 'Autre adresse']);
        $this->withToken($token)->getJson('/api/entites-geographiques/999999')->assertNotFound();
        $this->delete("/api/entites-geographiques/{$id}", [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => (string) $createdEntity->fresh()->lock_version, 'X-Change-Reason' => rawurlencode('Suppression de test')])->assertNoContent();
        $this->assertDatabaseMissing('entites_geographiques', ['id' => $id]);
    }

    public function test_access_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/entites-geographiques')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/entites-geographiques')->assertForbidden();
    }

    #[Group('mysql')]
    public function test_delete_returns_409_for_an_entity_used_in_modification_destinations(): void
    {
        $token = $this->administrativeToken();
        $entite = EntiteGeographique::factory()->create();
        DemandeModification::factory()->create(['destinations' => [['entite_geographique_id' => $entite->id, 'ordre' => 1]]]);

        $this->withToken($token)->delete("/api/entites-geographiques/{$entite->id}", [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => (string) $entite->fresh()->lock_version, 'X-Change-Reason' => rawurlencode('Suppression de test')])->assertConflict();
        $this->assertModelExists($entite);
    }
}
