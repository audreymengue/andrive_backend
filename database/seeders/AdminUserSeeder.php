<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\Role;
use App\Models\User;
use App\Services\ParametrageSecurity;
use App\Services\PasswordService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(PasswordService $passwordService): void
    {
        DB::transaction(function () use ($passwordService): void {
            app(ParametrageSecurity::class)->lock();
            $user = User::query()->where('email', 'admin@aninf.ga')->first();
            $before = $user?->only(['id', 'statut', 'direction_id', 'lock_version']);
            if ($before !== null) {
                $before['role_ids'] = $user->roles()->orderBy('id')->pluck('roles.id')->all();
            }

            if ($user === null) {
                $initialPassword = config('aninf.initial_admin_password');

                if (! is_string($initialPassword) || trim($initialPassword) === '') {
                    throw new RuntimeException('La variable ANINF_INITIAL_ADMIN_PASSWORD doit être définie avant d’exécuter le seeder.');
                }

                if (! $passwordService->hasValidLength($initialPassword)) {
                    throw new RuntimeException('La variable ANINF_INITIAL_ADMIN_PASSWORD doit contenir entre 8 et 12 caractères.');
                }

                $direction = Direction::query()->whereKey(config('aninf.initial_admin_direction_id'))->where('statut', 'ACTIF')->first();
                if ($direction === null) {
                    throw new RuntimeException('Fournir une direction officielle active via ANINF_INITIAL_ADMIN_DIRECTION_ID avant de créer le premier administrateur.');
                }
                $user = User::query()->create([
                    'direction_id' => $direction->id,
                    'name' => 'Administrateur ANINF',
                    'nom' => 'Administrateur',
                    'prenom' => 'ANINF',
                    'email' => 'admin@aninf.ga',
                    'password' => $passwordService->hash($initialPassword),
                    'statut' => 'ACTIF',
                    'must_change_password' => true,
                ]);
            }

            $role = Role::query()
                ->where('system_key', 'super_admin')
                ->where('guard_name', 'web')
                ->firstOrFail();

            $user->assignRole($role);
            $user->refresh();
            $after = $user->only(['id', 'statut', 'direction_id', 'lock_version']);
            $after['role_ids'] = $user->roles()->orderBy('id')->pluck('roles.id')->all();
            if ($before !== $after) {
                $user->forceFill(['lock_version' => $before === null ? 0 : $user->lock_version + 1])->save();
                $after['lock_version'] = $user->lock_version;
                DB::table('parametrage_maintenance_audits')->insert(['origine' => self::class, 'object_type' => 'user', 'object_id' => $user->id, 'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_values' => json_encode($after, JSON_THROW_ON_ERROR), 'command_key' => (string) Str::uuid(), 'created_at' => now()]);
            }
            app(ParametrageSecurity::class)->assertAdministratorRemains();
        }, 3);
    }
}
