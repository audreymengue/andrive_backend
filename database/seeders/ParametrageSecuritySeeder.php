<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\ParametrageSecurity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ParametrageSecuritySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            app(ParametrageSecurity::class)->lock();
            foreach (['super_admin' => ParametrageSecurity::adminPermissions(), 'demandeur' => ParametrageSecurity::demandeurPermissions()] as $key => $codes) {
                $role = Role::query()->where('system_key', $key)->first() ?? Role::query()->firstOrCreate(['name' => $key, 'guard_name' => 'web'], ['statut' => 'ACTIF']);
                $role->refresh();
                $before = $role->wasRecentlyCreated ? null : $role->only(['name', 'statut', 'system_key', 'lock_version']);
                if ($before !== null) {
                    $before['permissions'] = $role->permissions()->orderBy('name')->pluck('name')->all();
                }
                $role->forceFill(['system_key' => $key, 'statut' => 'ACTIF'])->save();
                foreach ($codes as $code) {
                    $permission = Permission::query()->firstOrCreate(['name' => $code, 'guard_name' => 'web'], ['libelle' => $code]);
                    $role->givePermissionTo($permission);
                }
                $after = $role->only(['name', 'statut', 'system_key', 'lock_version']);
                $after['permissions'] = $role->permissions()->orderBy('name')->pluck('name')->all();
                if ($before !== $after) {
                    $role->forceFill(['lock_version' => $before === null ? 0 : $role->lock_version + 1])->save();
                    $after['lock_version'] = $role->lock_version;
                    DB::table('parametrage_maintenance_audits')->insert(['origine' => self::class, 'object_type' => 'role', 'object_id' => $role->id, 'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_values' => json_encode($after, JSON_THROW_ON_ERROR), 'command_key' => (string) Str::uuid(), 'created_at' => now()]);
                }
            }
        }, 3);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
