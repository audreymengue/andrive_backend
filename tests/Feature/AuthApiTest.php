<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ParametrageSecurity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    private const string TEMPORARY_PASSWORD = 'Temp#1234';

    public function test_login_returns_a_sanctum_token_for_an_active_user(): void
    {
        $user = User::factory()->create([
            'email' => 'jean@aninf.ga',
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'must_change_password' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jean@aninf.ga',
            'password' => self::TEMPORARY_PASSWORD,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Connexion réussie.')
            ->assertJsonPath('data.must_change_password', true)
            ->assertExactJsonStructure([
                'message',
                'data' => ['token', 'must_change_password'],
            ])
            ->assertJsonMissingPath('data.user');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_database_seeder_creates_the_configured_super_administrator(): void
    {
        config()->set('aninf.initial_admin_password', 'Initial#8');
        $this->configureInitialAdminDirection();

        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@aninf.ga')->firstOrFail();

        $this->assertSame('ACTIF', $user->statut);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Initial#8', $user->password));
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertSame('super_admin', $user->roles()->firstOrFail()->system_key);
        $this->assertEqualsCanonicalizing(ParametrageSecurity::adminPermissions(), $user->getAllPermissions()->pluck('name')->all());
        $this->assertFalse($user->hasPermissionTo('read_demande'));
        $this->assertFalse($user->hasPermissionTo('create_demande'));
        $this->assertFalse($user->hasPermissionTo('update_demande'));
        $this->assertFalse($user->hasPermissionTo('submit_demande'));
        $this->assertFalse($user->hasPermissionTo('cancel_demande'));
    }

    public function test_login_returns_401_for_incorrect_credentials(): void
    {
        User::factory()->create([
            'email' => 'jean@aninf.ga',
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
        ]);

        $this->postJson('/api/login', [
            'email' => 'jean@aninf.ga',
            'password' => 'incorrect',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Les identifiants fournis sont incorrects.');
    }

    public function test_login_returns_403_for_an_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactif@aninf.ga',
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'statut' => 'INACTIF',
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactif@aninf.ga',
            'password' => self::TEMPORARY_PASSWORD,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Ce compte est désactivé.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user_without_a_permission_requirement(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_me_remains_available_when_password_change_is_required(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_logout_revokes_the_current_sanctum_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('current-token')->plainTextToken;
        $tokenId = $user->tokens()->where('name', 'current-token')->value('id');

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Déconnexion réussie.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_a_token_cannot_be_used_after_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('current-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->flushHeaders();
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Non authentifié.');
    }

    public function test_logout_requires_a_bearer_token(): void
    {
        $this->postJson('/api/logout')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Non authentifié.');
    }

    public function test_logout_rejects_an_invalid_bearer_token(): void
    {
        $this->withToken('invalid-token')
            ->postJson('/api/logout')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Non authentifié.');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('token-a')->plainTextToken;
        $tokenAId = $user->tokens()->where('name', 'token-a')->value('id');
        $tokenB = $user->createToken('token-b')->plainTextToken;
        $tokenBId = $user->tokens()->where('name', 'token-b')->value('id');

        $this->withToken($tokenA)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenAId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenBId]);

        $this->flushHeaders();
        app('auth')->forgetGuards();

        $this->withToken($tokenA)->getJson('/api/me')->assertUnauthorized();

        $this->flushHeaders();
        app('auth')->forgetGuards();

        $this->withToken($tokenB)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_business_endpoints_return_403_when_password_change_is_required(): void
    {
        $permission = Permission::factory()->create(['name' => 'read_user']);
        $role = Role::factory()->create();
        $role->givePermissionTo($permission);
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole($role);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous devez modifier votre mot de passe avant de poursuivre.');
    }

    public function test_first_password_change_without_current_password_replaces_tokens_and_unlocks_business_endpoints(): void
    {
        $permission = Permission::factory()->create(['name' => 'read_demande']);
        $role = Role::factory()->create();
        $role->givePermissionTo($permission);
        $user = User::factory()->create([
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'must_change_password' => true,
        ]);
        $user->assignRole($role);
        $oldToken = $user->createToken('old-token')->plainTextToken;
        $oldTokenId = $user->tokens()->value('id');

        $response = $this->withToken($oldToken)->putJson('/api/me/password', [
            'new_password' => 'Nouveau#8',
            'new_password_confirmation' => 'Nouveau#8',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Mot de passe modifié avec succès.')
            ->assertJsonPath('data.must_change_password', false);

        $user->refresh();
        $this->assertTrue(Hash::check('Nouveau#8', $user->password));
        $this->assertFalse(Hash::check(self::TEMPORARY_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);

        $this->withToken($response->json('data.token'))->getJson('/api/demandes')->assertOk();
        $this->getJson('/api/users')->assertForbidden();
    }

    #[DataProvider('invalidNewPasswordLengths')]
    public function test_first_password_change_returns_422_when_the_new_password_length_is_outside_the_allowed_range(string $password): void
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'must_change_password' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'new_password' => $password,
            'new_password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Les données fournies sont invalides.')
            ->assertJsonPath('errors.new_password.0', 'Votre mot de passe doit comporter entre 8 et 12 caractères.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNewPasswordLengths(): array
    {
        return [
            'seven characters' => ['Ab#1234'],
            'thirteen characters' => ['Ab#1234567890'],
        ];
    }

    #[DataProvider('validNewPasswordLengths')]
    public function test_first_password_change_accepts_new_password_lengths_at_the_allowed_boundaries(string $password): void
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'must_change_password' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'new_password' => $password,
            'new_password_confirmation' => $password,
        ])->assertOk();

        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validNewPasswordLengths(): array
    {
        return [
            'eight characters' => ['Ab#12345'],
            'twelve characters' => ['Ab#123456789'],
        ];
    }

    public function test_first_password_change_returns_422_when_confirmation_differs(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'must_change_password' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'new_password' => 'Nouveau#8',
            'new_password_confirmation' => 'Different#9',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'La confirmation du champ nouveau mot de passe ne correspond pas.');

        $this->assertTrue(Hash::check(self::TEMPORARY_PASSWORD, $user->fresh()->password));
    }

    public function test_first_password_change_returns_422_when_it_reuses_the_temporary_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::TEMPORARY_PASSWORD),
            'must_change_password' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'new_password' => self::TEMPORARY_PASSWORD,
            'new_password_confirmation' => self::TEMPORARY_PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Le nouveau mot de passe doit être différent du mot de passe temporaire.');

        $this->assertTrue(Hash::check(self::TEMPORARY_PASSWORD, $user->fresh()->password));
    }

    public function test_normal_password_change_returns_422_when_current_password_is_missing(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Ancien#8'),
            'must_change_password' => false,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'new_password' => 'Nouveau#8',
            'new_password_confirmation' => 'Nouveau#8',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'Le champ mot de passe actuel est obligatoire.');

        $this->assertTrue(Hash::check('Ancien#8', $user->fresh()->password));
    }

    public function test_normal_password_change_returns_401_when_the_current_password_is_incorrect(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Ancien#8'),
            'must_change_password' => false,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'current_password' => 'incorrect',
            'new_password' => 'Nouveau#8',
            'new_password_confirmation' => 'Nouveau#8',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'L’ancien mot de passe est incorrect.');

        $user->refresh();
        $this->assertTrue(Hash::check('Ancien#8', $user->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_normal_password_change_returns_422_when_the_new_password_is_longer_than_twelve_characters(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Ancien#8'),
            'must_change_password' => false,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'current_password' => 'Ancien#8',
            'new_password' => 'Ab#1234567890',
            'new_password_confirmation' => 'Ab#1234567890',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Votre mot de passe doit comporter entre 8 et 12 caractères.');

        $this->assertTrue(Hash::check('Ancien#8', $user->fresh()->password));
    }

    public function test_normal_password_change_accepts_the_correct_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Ancien#8'),
            'must_change_password' => false,
            'temporary_password' => Crypt::encryptString('AncienTemp1!'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', [
            'current_password' => 'Ancien#8',
            'new_password' => 'Nouveau#8',
            'new_password_confirmation' => 'Nouveau#8',
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertTrue(Hash::check('Nouveau#8', $user->fresh()->password));
        $this->assertNull($user->fresh()->temporary_password);
    }

    public function test_login_returns_429_after_five_attempts_per_email_and_ip(): void
    {
        $payload = [
            'email' => 'rate-limit@aninf.ga',
            'password' => 'incorrect',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/login', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Trop de tentatives de connexion. Réessayez dans une minute.');
    }
}
