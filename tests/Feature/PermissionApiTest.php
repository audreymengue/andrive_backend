<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class PermissionApiTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_permissions_endpoint_requires_the_matching_permission(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/permissions')->assertForbidden();
    }

    public function test_create_permission_exposes_the_contract_code_and_label(): void
    {
        $token = $this->administrativeToken();

        $this->withToken($token)->command('POST', '/api/permissions', [
            'libelle' => 'Lire les véhicules',
            'code' => 'read_test_vehicle',
            'description' => 'Permet de consulter les véhicules.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.libelle', 'Lire les véhicules')
            ->assertJsonPath('data.code', 'read_test_vehicle');

        $this->assertDatabaseHas('permissions', [
            'name' => 'read_test_vehicle',
            'libelle' => 'Lire les véhicules',
        ]);
    }

    public function test_delete_permission_returns_409_when_it_is_assigned_to_a_role(): void
    {
        $token = $this->administrativeToken();
        $permission = Permission::factory()->create(['name' => 'test_custom_permission']);
        $role = Role::factory()->create();
        $role->givePermissionTo($permission);

        $this->withToken($token)->command('DELETE', "/api/permissions/{$permission->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'SYSTEM_OBJECT_PROTECTED');

        $this->assertModelExists($permission);
        $this->assertTrue($role->fresh()->hasPermissionTo($permission));
    }

    public function test_delete_permission_returns_204_when_it_is_not_assigned_to_a_role(): void
    {
        $token = $this->administrativeToken();
        $permission = Permission::factory()->create(['name' => 'test_custom_permission']);

        $this->withToken($token)->command('DELETE', "/api/permissions/{$permission->id}")->assertNoContent();

        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
    }
}
