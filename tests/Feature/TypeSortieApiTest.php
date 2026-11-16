<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\Permission;
use App\Models\TypeSortie;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TypeSortieApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_crud_and_validation_with_administrator_defined_values(): void
    {
        $user = User::factory()->create();
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $user->givePermissionTo(Permission::factory()->create(['name' => $action.'_type_sortie']));
        }
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/types-sortie')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->postJson('/api/types-sortie', [])->assertUnprocessable()->assertJsonValidationErrors(['libelle', 'code']);
        $response = $this->withToken($token)->postJson('/api/types-sortie', ['libelle' => 'Catégorie personnalisée', 'code' => 'TEST'])->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('types_sortie', ['id' => $id, 'code' => 'TEST']);
        $this->withToken($token)->postJson('/api/types-sortie', ['libelle' => 'Doublon', 'code' => 'TEST'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withToken($token)->getJson("/api/types-sortie/{$id}")->assertOk();
        $this->withToken($token)->putJson("/api/types-sortie/{$id}", ['libelle' => 'Modifié', 'code' => 'TEST'])->assertOk()->assertJsonPath('data.libelle', 'Modifié');
        $this->assertDatabaseHas('types_sortie', ['id' => $id, 'libelle' => 'Modifié']);
        $this->withToken($token)->getJson('/api/types-sortie/999999')->assertNotFound();
        $this->withToken($token)->deleteJson("/api/types-sortie/{$id}")->assertNoContent();
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
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::factory()->create(['name' => 'delete_type_sortie']));
        $type = TypeSortie::factory()->create();
        Demande::factory()->create(['type_sortie_id' => $type->id]);

        $this->withToken($user->createToken('test')->plainTextToken)->deleteJson("/api/types-sortie/{$type->id}")->assertConflict();
        $this->assertModelExists($type);
    }
}
