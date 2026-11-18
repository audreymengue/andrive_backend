<?php

namespace Database\Seeders;

use App\Models\Chauffeur;
use App\Models\Direction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class QaRoleAccountsSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string TEST_PASSWORD = 'ANINF-QA26';

    /**
     * Run the database seeds.
     */
    public function run(PasswordService $passwordService, ?int $directionId = null): void
    {
        $this->ensureTestDatabaseIsConfigured();
        $this->call(PermissionSeeder::class);

        $directionQuery = Direction::query()->where('statut', 'ACTIF');
        if ($directionId !== null) {
            $directionQuery->whereKey($directionId);
        } else {
            $directionQuery->orderByRaw("CASE WHEN code = 'DSI' THEN 0 ELSE 1 END")
                ->orderBy('id');
        }
        $direction = $directionQuery->first();

        if ($direction === null) {
            throw new RuntimeException('Une direction active est requise pour créer les comptes QA.');
        }

        /** @var array<string, array{name: string, nom: string, prenom: string, email: string, description: string, permissions: list<string>}> $profiles */
        $profiles = [
            'dag' => [
                'name' => 'QA DAG',
                'nom' => 'QA',
                'prenom' => 'DAG',
                'email' => 'qa.dag@example.test',
                'description' => 'Profil QA DAG',
                'permissions' => [
                    'read_demande_traitement',
                    'read_course',
                    'create_course',
                    'update_course',
                    'confirm_course',
                    'read_vehicle',
                    'read_driver',
                ],
            ],
            'chauffeur' => [
                'name' => 'QA Chauffeur',
                'nom' => 'QA',
                'prenom' => 'Chauffeur',
                'email' => 'qa.chauffeur@example.test',
                'description' => 'Profil QA chauffeur',
                'permissions' => [
                    'read_course',
                    'start_course',
                    'finish_course',
                    'report_course_incident',
                ],
            ],
            'gestionnaire_transport' => [
                'name' => 'QA Transport',
                'nom' => 'QA',
                'prenom' => 'Transport',
                'email' => 'qa.transport@example.test',
                'description' => 'Profil QA gestion Transport',
                'permissions' => [
                    'read_transport_signalement',
                    'read_resource_availability',
                    'manage_resource_availability',
                    'confirm_resource_return',
                    'read_vehicle',
                    'read_driver',
                ],
            ],
        ];

        DB::transaction(function () use ($direction, $passwordService, $profiles): void {
            foreach ($profiles as $roleName => $profile) {
                $role = Role::query()->updateOrCreate(
                    ['name' => $roleName, 'guard_name' => 'web'],
                    ['description' => $profile['description'], 'statut' => 'ACTIF'],
                );
                $role->syncPermissions(Permission::query()->whereIn('name', $profile['permissions'])->get());

                $user = User::query()->updateOrCreate(
                    ['email' => $profile['email']],
                    [
                        'name' => $profile['name'],
                        'nom' => $profile['nom'],
                        'prenom' => $profile['prenom'],
                        'password' => $passwordService->hash(self::TEST_PASSWORD),
                        'statut' => 'ACTIF',
                        'direction_id' => $direction->id,
                        'must_change_password' => false,
                        'temporary_password' => null,
                    ],
                );
                $user->syncRoles($role);

                if ($roleName === 'chauffeur') {
                    Chauffeur::query()->firstOrCreate(
                        ['user_id' => $user->id],
                        ['statut' => 'ACTIF'],
                    );
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureTestDatabaseIsConfigured(): void
    {
        $databaseName = config('database.connections.mysql.database');
        $connection = DB::connection();
        $isIsolatedTestDatabase = app()->runningUnitTests()
            && $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';

        if (
            $databaseName !== TestDataSeeder::TEST_DATABASE
            || (! $isIsolatedTestDatabase && $connection->getDatabaseName() !== TestDataSeeder::TEST_DATABASE)
        ) {
            throw new RuntimeException('Le seeder des comptes QA ne peut être exécuté que sur la base aninf_test.');
        }
    }
}
