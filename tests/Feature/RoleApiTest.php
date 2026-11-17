<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_roles_endpoint_requires_the_matching_permission(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/roles')->assertForbidden();
    }

    public function test_create_role_persists_its_status_and_description(): void
    {
        $token = $this->administrativeToken();

        $this->withToken($token)->command('POST', '/api/roles', [
            'nom' => 'gestionnaire',
            'description' => 'Gère les demandes.',
            'statut' => 'INACTIF',
        ])
            ->assertCreated()
            ->assertJsonPath('data.nom', 'gestionnaire')
            ->assertJsonPath('data.statut', 'INACTIF');

        $this->assertDatabaseHas('roles', [
            'name' => 'gestionnaire',
            'description' => 'Gère les demandes.',
            'statut' => 'INACTIF',
        ]);
    }

    public function test_assigning_permissions_replaces_role_permissions(): void
    {
        $token = $this->administrativeToken();
        $role = Role::factory()->create();
        $permission = Permission::query()->where('name', 'read_user')->firstOrFail();

        $this->withToken($token)->command('PUT', "/api/roles/{$role->id}/permissions", [
            'permission_ids' => [$permission->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.permissions.0.code', 'read_user');

        $this->assertTrue($role->fresh()->hasPermissionTo($permission));
    }

    public function test_delete_role_returns_409_when_it_is_assigned_to_a_user(): void
    {
        $token = $this->administrativeToken();
        $role = Role::factory()->create();
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->withToken($token)->command('DELETE', "/api/roles/{$role->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Impossible de supprimer ce rôle car il est encore utilisé.')
            ->assertJsonPath('code', 'REFERENCE_IN_USE');

        $this->assertModelExists($role);
        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_delete_role_returns_204_when_it_is_not_assigned_to_a_user(): void
    {
        $token = $this->administrativeToken();
        $role = Role::factory()->create();

        $this->withToken($token)->command('DELETE', "/api/roles/{$role->id}")->assertNoContent();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_delete_role_returns_409_when_it_has_assigned_permissions(): void
    {
        $token = $this->administrativeToken();
        $role = Role::factory()->create();
        $permission = Permission::query()->where('name', 'read_user')->firstOrFail();
        $role->givePermissionTo($permission);

        $this->withToken($token)->command('DELETE', "/api/roles/{$role->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Impossible de supprimer ce rôle car il est encore utilisé.')
            ->assertJsonPath('code', 'REFERENCE_IN_USE');

        $this->assertModelExists($role);
        $this->assertTrue($role->fresh()->hasPermissionTo($permission));
    }

    public function test_assigning_an_unknown_permission_returns_404_without_changing_permissions(): void
    {
        $token = $this->administrativeToken();
        $role = Role::factory()->create();

        $this->withToken($token)->command('PUT', "/api/roles/{$role->id}/permissions", [
            'permission_ids' => [999_999],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');

        $this->assertCount(0, $role->fresh()->permissions);
    }
}
