<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $isTestDatabase = config('database.connections.mysql.database') === TestDataSeeder::TEST_DATABASE;

        if (app()->runningUnitTests()) {
            $this->call($isTestDatabase ? TestDataSeeder::class : [
                PermissionSeeder::class,
                RoleSeeder::class,
                AdminUserSeeder::class,
            ]);

            return;
        }

        if ($isTestDatabase) {
            $this->call([
                TestDataSeeder::class,
                OfficialOrganisationSeeder::class,
            ]);

            return;
        }

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            OfficialOrganisationSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
