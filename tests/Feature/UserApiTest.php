<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_users_endpoints_require_authentication(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_user_management_returns_403_without_the_required_permission(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Habilitation administrative requise.')
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_create_user_returns_a_unique_hashed_temporary_password_without_exposing_it(): void
    {
        $token = $this->administrativeToken();
        $direction = Direction::factory()->create();

        $response = $this->withToken($token)->command('POST', '/api/users', [
            'nom' => 'MBOUROU',
            'prenom' => 'Jean',
            'email' => 'jean@aninf.ga',
            'direction_id' => $direction->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Utilisateur créé avec succès.')
            ->assertJsonPath('data.nom', 'MBOUROU')
            ->assertJsonPath('data.prenom', 'Jean')
            ->assertJsonPath('data.direction_id', $direction->id)
            ->assertJsonPath('data.statut', 'ACTIF')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.temporary_password');

        $user = User::query()->where('email', 'jean@aninf.ga')->firstOrFail();
        $response->assertJsonMissingPath('data.temporary_password');
        $temporaryPassword = $this->revealTemporaryPassword($user);

        $this->assertIsString($temporaryPassword);
        $this->assertSame(12, Str::length($temporaryPassword));
        $this->assertSame('Jean MBOUROU', $user->name);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertNotSame($temporaryPassword, $user->password);
        $this->assertSame('ACTIF', $user->statut);
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame($temporaryPassword, $user->temporary_password);
        $this->assertSame($temporaryPassword, Crypt::decryptString($user->temporary_password));
        $this->assertArrayNotHasKey('temporary_password', $user->toArray());
    }

    public function test_create_user_rejects_fields_not_declared_by_the_contract(): void
    {
        $token = $this->administrativeToken();
        $direction = Direction::factory()->create();

        $this->withToken($token)->command('POST', '/api/users', [
            'nom' => 'MBOUROU',
            'prenom' => 'Jean',
            'email' => 'extra-field@aninf.ga',
            'direction_id' => $direction->id,
            'statut' => 'INACTIF',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('statut');

        $this->assertDatabaseMissing('users', ['email' => 'extra-field@aninf.ga']);
    }

    public function test_successive_user_creations_generate_different_temporary_passwords(): void
    {
        $token = $this->administrativeToken();
        $direction = Direction::factory()->create();

        $firstResponse = $this->withToken($token)->command('POST', '/api/users', [
            'nom' => 'MBOUROU',
            'prenom' => 'Jean',
            'email' => 'jean@aninf.ga',
            'direction_id' => $direction->id,
        ]);
        $secondResponse = $this->withToken($token)->command('POST', '/api/users', [
            'nom' => 'MBOUROU',
            'prenom' => 'Jeanne',
            'email' => 'jeanne@aninf.ga',
            'direction_id' => $direction->id,
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
        $this->assertNotSame(
            $this->revealTemporaryPassword(User::query()->where('email', 'jean@aninf.ga')->firstOrFail()),
            $this->revealTemporaryPassword(User::query()->where('email', 'jeanne@aninf.ga')->firstOrFail()),
        );
    }

    public function test_user_created_with_a_temporary_password_can_log_in_and_must_change_it(): void
    {
        $token = $this->administrativeToken();
        $direction = Direction::factory()->create();

        $response = $this->withToken($token)->command('POST', '/api/users', [
            'nom' => 'MBOUROU',
            'prenom' => 'Jean',
            'email' => 'jean@aninf.ga',
            'direction_id' => $direction->id,
        ])->assertCreated()->assertJsonMissingPath('data.temporary_password');

        $temporaryPassword = $this->revealTemporaryPassword(User::query()->where('email', 'jean@aninf.ga')->firstOrFail());
        $this->flushHeaders();
        app('auth')->forgetGuards();

        $this->postJson('/api/login', [
            'email' => 'jean@aninf.ga',
            'password' => $temporaryPassword,
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_create_user_returns_404_for_an_unknown_direction(): void
    {
        $token = $this->administrativeToken();

        $this->withToken($token)->command('POST', '/api/users', [
            'nom' => 'MBOUROU',
            'prenom' => 'Jean',
            'email' => 'jean@aninf.ga',
            'direction_id' => 999_999,
        ])
            ->assertNotFound()->assertJsonPath('message', 'Ressource introuvable.');

        $this->assertDatabaseMissing('users', ['email' => 'jean@aninf.ga']);
    }

    public function test_update_user_keeps_the_legacy_name_in_sync(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create([
            'nom' => 'MBOUROU',
            'prenom' => 'Jean',
            'name' => 'Jean MBOUROU',
            'direction_id' => Direction::factory()->create()->id,
        ]);

        $this->withToken($token)->command('PUT', "/api/users/{$user->id}", [
            'prenom' => 'Jeanne',
        ])
            ->assertOk()
            ->assertJsonPath('data.prenom', 'Jeanne')
            ->assertJsonPath('data.direction_id', $user->direction_id);

        $user->refresh();
        $this->assertSame('Jeanne MBOUROU', $user->name);
        $this->assertSame('Jeanne', $user->prenom);
        $this->assertNotNull($user->direction_id);
    }

    public function test_update_user_returns_404_for_an_unknown_direction(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create();

        $this->withToken($token)->command('PUT', "/api/users/{$user->id}", [
            'direction_id' => 999_999,
        ])
            ->assertNotFound()->assertJsonPath('message', 'Ressource introuvable.');

        $this->assertNull($user->fresh()->direction_id);
    }

    public function test_deactivate_user_revokes_existing_tokens(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create();
        $user->createToken('mobile-token');

        $this->withToken($token)->command('PATCH', "/api/users/{$user->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.statut', 'INACTIF');

        $user->refresh();
        $this->assertSame('INACTIF', $user->statut);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_password_generates_a_temporary_password_and_revokes_existing_tokens(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create([
            'password' => Hash::make('Autre#88'),
            'must_change_password' => false,
        ]);
        $oldToken = $user->createToken('mobile-token')->plainTextToken;

        $response = $this->withToken($token)->command('POST', "/api/users/{$user->id}/reset-password");

        $response
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.user');

        $user->refresh();
        $response->assertJsonMissingPath('data.temporary_password');
        $temporaryPassword = $this->revealTemporaryPassword($user);

        $this->assertIsString($temporaryPassword);
        $this->assertSame(12, Str::length($temporaryPassword));
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertNotSame($temporaryPassword, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());

        $this->flushHeaders();
        app('auth')->forgetGuards();

        $this->withToken($oldToken)->getJson('/api/me')->assertUnauthorized();

        $this->flushHeaders();
        app('auth')->forgetGuards();

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $temporaryPassword,
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.user');

        $this->withToken($loginResponse->json('data.token'))->putJson('/api/me/password', [
            'new_password' => 'Nouveau#8',
            'new_password_confirmation' => 'Nouveau#8',
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Nouveau#8', $user->fresh()->password));
    }

    public function test_show_user_returns_a_specific_404_message_when_the_user_does_not_exist(): void
    {
        $token = $this->administrativeToken();

        $this->withToken($token)->getJson('/api/users/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'L’utilisateur demandé n’existe pas.');
    }

    public function test_assigning_an_inactive_role_returns_422_without_changing_roles(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create();
        $inactiveRole = Role::factory()->create(['statut' => 'INACTIF']);

        $this->withToken($token)->command('PUT', "/api/users/{$user->id}/roles", [
            'role_ids' => [$inactiveRole->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Les données fournies sont invalides.')
            ->assertJsonPath('errors.role_ids.0', 'Un rôle inactif ne peut pas être attribué à un utilisateur.');

        $this->assertFalse($user->hasRole($inactiveRole));
    }

    public function test_assigning_active_roles_replaces_user_roles(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'gestionnaire']);

        $this->withToken($token)->command('PUT', "/api/users/{$user->id}/roles", [
            'role_ids' => [$role->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.roles.0.nom', 'gestionnaire');

        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_assigning_an_unknown_role_returns_404_without_changing_roles(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create();

        $this->withToken($token)->command('PUT', "/api/users/{$user->id}/roles", [
            'role_ids' => [999_999],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_users_index_accepts_per_page_100(): void
    {
        $token = $this->administrativeToken();

        $this->withToken($token)->getJson('/api/users?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_users_index_returns_422_when_per_page_exceeds_100(): void
    {
        $token = $this->administrativeToken();

        $this->withToken($token)->getJson('/api/users?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_update_user_rejects_fields_not_declared_by_the_contract(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create();

        $this->withToken($token)->command('PUT', '/api/users/'.$user->id, [
            'statut' => 'INACTIF',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('statut');

        $this->assertSame('ACTIF', $user->fresh()->statut);
        $this->assertSame(0, $user->fresh()->lock_version);
    }

    public function test_authorized_show_never_reveals_a_temporary_password(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create(['must_change_password' => true, 'temporary_password' => Crypt::encryptString('Temporaire1!')]);

        $this->withToken($token)->getJson("/api/users/{$user->id}")->assertOk()->assertJsonMissingPath('data.temporary_password');
        $user->update(['must_change_password' => false]);
        $this->withToken($token)->getJson("/api/users/{$user->id}")->assertOk()->assertJsonMissingPath('data.temporary_password');
    }

    public function test_temporary_password_is_not_exposed_in_me_or_to_an_unauthorized_user(): void
    {
        $user = User::factory()->create(['must_change_password' => true, 'temporary_password' => Crypt::encryptString('Temporaire1!')]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonMissingPath('data.temporary_password');
        $this->withToken($token)->getJson("/api/users/{$user->id}")->assertForbidden()->assertJsonMissingPath('data.temporary_password');
    }

    public function test_reset_replaces_encrypted_temporary_and_password_change_erases_it(): void
    {
        $token = $this->administrativeToken();
        $user = User::factory()->create(['temporary_password' => Crypt::encryptString('AncienTemp1!')]);

        $response = $this->withToken($token)->command('POST', "/api/users/{$user->id}/reset-password")->assertOk();
        $response->assertJsonMissingPath('data.temporary_password');
        $temporary = $this->revealTemporaryPassword($user);
        $this->assertNotSame('AncienTemp1!', $temporary);
        $this->assertSame($temporary, Crypt::decryptString($user->fresh()->temporary_password));
        $this->assertNotSame($temporary, $user->fresh()->temporary_password);
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $userToken = $user->createToken('change')->plainTextToken;
        $this->withToken($userToken)->putJson('/api/me/password', ['new_password' => 'Nouveau#8', 'new_password_confirmation' => 'Nouveau#8'])->assertOk();
        $this->assertNull($user->fresh()->temporary_password);
        $this->assertFalse($user->fresh()->must_change_password);
    }
}
