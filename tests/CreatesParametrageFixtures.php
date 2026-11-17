<?php

namespace Tests;

use App\Models\Direction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait CreatesParametrageFixtures
{
    private function configureInitialAdminDirection(): Direction
    {
        $direction = Direction::factory()->create(['code' => 'TEST_BOOTSTRAP', 'nom' => 'TEST direction initiale', 'statut' => 'ACTIF']);
        config()->set('aninf.initial_admin_direction_id', $direction->id);

        return $direction;
    }

    private function administrativeToken(): string
    {
        $this->seed(ParametrageSecuritySeeder::class);
        $user = User::factory()->create(['direction_id' => Direction::factory()->create()->id, 'password' => Hash::make('AdminPass123')]);
        $user->assignRole(Role::query()->where('system_key', 'super_admin')->firstOrFail());

        return $user->createToken('legacy-suite')->plainTextToken;
    }

    private function command(string $method, string $path, array $data = []): TestResponse
    {
        preg_match('#^/api/(users|roles|permissions)/(\d+)#', $path, $matches);
        $model = isset($matches[1]) ? match ($matches[1]) {
            'users' => User::class, 'roles' => Role::class, 'permissions' => Permission::class,
        } : null;
        $version = $model === null ? 0 : ($model::query()->find($matches[2])?->lock_version ?? 0);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        if ($method === 'DELETE') {
            return $this->delete($path, [], [...$headers, 'Accept' => 'application/json', 'X-Expected-Version' => (string) $version, 'X-Change-Reason' => rawurlencode('TEST suppression')]);
        }
        $control = [];
        if (! ($method === 'POST' && in_array($path, ['/api/users', '/api/roles', '/api/permissions'], true))) {
            $control['motif'] = 'TEST suite generale';
        }
        if (isset($matches[2])) {
            $control['version_attendue'] = $version;
        }
        if (str_ends_with($path, '/reset-password')) {
            $control['actor_password'] = 'AdminPass123';
        }

        return $this->json($method, $path, [...$control, ...$data], $headers);
    }

    private function revealTemporaryPassword(User $user): string
    {
        $user->refresh();
        $response = $this->postJson('/api/users/'.$user->id.'/temporary-password/reveal', [
            'version_attendue' => $user->lock_version,
            'password_generation' => $user->password_generation,
            'motif' => 'TEST communication controlee',
            'current_password' => 'AdminPass123',
        ])->assertOk()->assertHeaderContains('Cache-Control', 'no-store');
        $this->assertIsString($response->json('data.temporary_password'));

        return $response->json('data.temporary_password');
    }
}
