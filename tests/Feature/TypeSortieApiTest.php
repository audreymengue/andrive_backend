<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\TypeSortie;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class TypeSortieApiTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_crud_and_validation_with_administrator_defined_values(): void
    {
        $token = $this->administrativeToken();
        $this->withToken($token)->getJson('/api/types-sortie')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->postJson('/api/types-sortie', [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['libelle', 'code']);
        $response = $this->withToken($token)->postJson('/api/types-sortie', ['libelle' => 'Catégorie personnalisée', 'code' => 'TEST'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('types_sortie', ['id' => $id, 'code' => 'TEST']);
        $this->withToken($token)->postJson('/api/types-sortie', ['libelle' => 'Doublon', 'code' => 'TEST'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withToken($token)->getJson("/api/types-sortie/{$id}")->assertOk();
        $createdType = TypeSortie::query()->findOrFail($id);
        $this->withToken($token)->putJson("/api/types-sortie/{$id}", ['libelle' => 'Modifié', 'version_attendue' => $createdType->lock_version], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.libelle', 'Modifié');
        $this->assertDatabaseHas('types_sortie', ['id' => $id, 'libelle' => 'Modifié', 'code' => 'TEST']);
        $this->withToken($token)->getJson('/api/types-sortie/999999')->assertNotFound();
        $this->withToken($token)->delete("/api/types-sortie/{$id}", [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => (string) $createdType->fresh()->lock_version, 'X-Change-Reason' => rawurlencode('Suppression de test')])->assertNoContent();
        $this->assertDatabaseMissing('types_sortie', ['id' => $id]);
    }

    public function test_access_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/types-sortie')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/types-sortie')->assertForbidden();
    }

    public function test_delete_returns_409_for_a_type_used_by_a_demande(): void
    {
        $token = $this->administrativeToken();
        $type = TypeSortie::factory()->create();
        Demande::factory()->create(['type_sortie_id' => $type->id]);

        $this->withToken($token)->delete("/api/types-sortie/{$type->id}", [], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(), 'X-Expected-Version' => (string) $type->fresh()->lock_version, 'X-Change-Reason' => rawurlencode('Suppression de test')])->assertConflict();
        $this->assertModelExists($type);
    }
}
