<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_seeder_requires_the_initial_admin_password_configuration(): void
    {
        config()->set('aninf.initial_admin_password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La variable ANINF_INITIAL_ADMIN_PASSWORD doit être définie avant d’exécuter le seeder.');

        $this->seed(AdminUserSeeder::class);
    }

    #[DataProvider('invalidInitialAdminPasswords')]
    public function test_seeder_rejects_an_initial_admin_password_outside_the_allowed_length(string $initialPassword): void
    {
        config()->set('aninf.initial_admin_password', $initialPassword);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La variable ANINF_INITIAL_ADMIN_PASSWORD doit contenir entre 8 et 12 caractères.');

        $this->seed(AdminUserSeeder::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInitialAdminPasswords(): array
    {
        return [
            'seven characters' => ['Faux#7!'],
            'thirteen characters' => ['Fictif#123456'],
        ];
    }

    #[DataProvider('validInitialAdminPasswords')]
    public function test_seeder_creates_a_hashed_administrator_with_a_valid_initial_password(string $initialPassword): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $role = Role::query()->where('system_key', 'super_admin')->firstOrFail();
        $direction = $this->configureInitialAdminDirection();
        config()->set('aninf.initial_admin_password', $initialPassword);

        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'admin@aninf.ga')->firstOrFail();

        $this->assertTrue(Hash::check($initialPassword, $user->password));
        $this->assertNotSame($initialPassword, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole($role));
        $this->assertSame($direction->id, $user->direction_id);
    }

    public function test_seeder_refuses_a_missing_initial_direction_without_creating_an_administrator(): void
    {
        $this->seed(ParametrageSecuritySeeder::class);
        config()->set('aninf.initial_admin_password', 'Initial1!');
        config()->set('aninf.initial_admin_direction_id', null);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fournir une direction officielle active');

        try {
            $this->seed(AdminUserSeeder::class);
        } finally {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseMissing('parametrage_maintenance_audits', ['object_type' => 'user']);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validInitialAdminPasswords(): array
    {
        return [
            'eight characters' => ['Faux#123'],
            'twelve characters' => ['Fictif#12345'],
        ];
    }
}
