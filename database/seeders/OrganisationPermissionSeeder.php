<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class OrganisationPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $role = Role::query()->where('system_key', 'super_admin')->first();
            if ($role === null) {
                $role = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'], ['statut' => 'ACTIF', 'description' => 'Administration technique']);
                $role->forceFill(['system_key' => 'super_admin'])->save();
            }
            foreach (['direction', 'service'] as $kind) {
                foreach (['read', 'create', 'update', 'activate', 'deactivate'] as $action) {
                    $permission = Permission::query()->firstOrCreate(['name' => $action.'_'.$kind, 'guard_name' => 'web'], ['libelle' => $action.' '.$kind]);
                    $role->givePermissionTo($permission);
                    if ($kind === 'direction' && $action === 'read') {
                        $demandeur = Role::query()->where('name', 'demandeur')->where('guard_name', 'web')->first();
                        $demandeur?->givePermissionTo($permission);
                    }
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
