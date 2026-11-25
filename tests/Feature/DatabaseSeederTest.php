<?php

namespace Tests\Feature;

use App\Http\Requests\CreateDemandeRequest;
use App\Http\Requests\UpdateDemandeModificationRequest;
use App\Models\Demande;
use App\Models\DemandeModification;
use App\Models\Role;
use App\Models\User;
use App\Services\ParametrageSecurity;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\QaCourseResourcesSeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    public function test_default_seeder_initializes_the_complete_test_environment(): void
    {
        $this->configureTestSeeding();
        $this->travelTo('2026-09-29 09:00:00');

        $this->seed();

        $this->assertDatabaseCount('permissions', 67);
        foreach (['read_user', 'assign_permission', 'read_demande', 'create_demande', 'submit_demande', 'read_type_sortie', 'read_entite_geographique'] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'web']);
        }
        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('users', 9);
        $admin = User::query()->where('email', 'admin@aninf.ga')->firstOrFail();
        $this->assertTrue(Hash::check('Initial1!', $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertEqualsCanonicalizing(ParametrageSecurity::adminPermissions(), $admin->getAllPermissions()->pluck('name')->all());
        $this->assertSame('super_admin', Role::query()->where('name', 'super_admin')->firstOrFail()->system_key);
        foreach (['admin-test@aninf.ga', 'demandeur1@aninf.ga', 'demandeur2@aninf.ga', 'demandeur3@aninf.ga', 'inactif-test@aninf.ga'] as $email) {
            $this->assertDatabaseHas('users', ['email' => $email, 'must_change_password' => false]);
        }
        foreach (['qa.dag@example.test', 'qa.chauffeur@example.test', 'qa.transport@example.test'] as $email) {
            $this->assertDatabaseHas('users', ['email' => $email, 'statut' => 'ACTIF', 'must_change_password' => false]);
        }
        $this->assertDatabaseHas('chauffeurs', [
            'user_id' => User::query()->where('email', 'qa.chauffeur@example.test')->value('id'),
            'telephone' => QaCourseResourcesSeeder::DRIVER_PHONE,
            'statut' => 'ACTIF',
        ]);
        $this->assertDatabaseHas('vehicules', ['immatriculation' => QaCourseResourcesSeeder::PLATE, 'statut' => 'ACTIF', 'places_utiles' => 4]);
        $this->assertTrue(Hash::check('TestPass1!', User::query()->where('email', 'admin-test@aninf.ga')->firstOrFail()->password));
        $this->assertDatabaseCount('directions', 4);
        $this->assertDatabaseCount('types_sortie', 1);
        $this->assertDatabaseHas('types_sortie', ['code' => 'TEST_SORTIE']);
        $this->assertDatabaseCount('entites_geographiques', 7);
        $this->assertDatabaseHas('entites_geographiques', ['adresse' => 'Siège ANINF — démonstration', 'latitude' => null, 'longitude' => null]);
        $this->assertDatabaseCount('demandes', 12);
        $this->assertDatabaseCount('demande_destinations', 20);
        $this->assertDatabaseCount('demande_historiques', 19);
        $this->assertDatabaseCount('demande_modifications', 1);

        foreach (Demande::query()->with(['demandeur', 'direction', 'typeSortie', 'adresseDepart', 'adresseRetour', 'destinations.entiteGeographique', 'employes', 'objets'])->get() as $demande) {
            $this->assertSame('TEST_SORTIE', $demande->typeSortie->code);
            $this->assertSame($demande->direction_id, $demande->demandeur->direction_id);
            $this->assertMatchesRegularExpression(Demande::REFERENCE_PATTERN, $demande->reference);
            $payload = $demande->toArray();
            $request = new CreateDemandeRequest($payload);
            $validator = Validator::make($payload, $request->rules());
            $validator->after($request->after());
            $this->assertFalse($validator->fails(), $validator->errors()->toJson());
            foreach ($demande->destinations as $destination) {
                $this->assertNotNull($destination->entiteGeographique);
            }
        }
        $modification = DemandeModification::query()->with('demande')->firstOrFail();
        $this->assertSame($modification->demande->type_sortie_id, $modification->type_sortie_id);
        $this->assertSame($modification->demande->adresse_depart_id, $modification->adresse_depart_id);
        $payload = $modification->toArray();
        $request = new UpdateDemandeModificationRequest($payload);
        $validator = Validator::make($payload, $request->rules());
        $validator->after($request->after());
        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_reseeding_on_another_day_keeps_record_counts_and_references_stable(): void
    {
        $this->configureTestSeeding();
        $this->travelTo('2026-09-29 09:00:00');
        $this->seed(DatabaseSeeder::class);
        $tables = ['permissions', 'roles', 'users', 'directions', 'types_sortie', 'entites_geographiques', 'demandes', 'demande_destinations', 'demande_employes', 'demande_objets', 'demande_historiques', 'demande_modifications', 'demande_reference_sequences', 'chauffeurs', 'vehicules', 'role_has_permissions', 'model_has_roles'];
        $counts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $references = Demande::query()->orderBy('id')->pluck('reference')->all();
        $adminPasswordHash = User::query()->where('email', 'admin@aninf.ga')->value('password');
        $this->travelTo('2026-09-30 09:00:00');

        $this->seed(DatabaseSeeder::class);

        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame($references, Demande::query()->orderBy('id')->pluck('reference')->all());
        $this->assertSame($adminPasswordHash, User::query()->where('email', 'admin@aninf.ga')->value('password'));
    }

    public function test_standard_environment_only_receives_generic_seeders(): void
    {
        config()->set('database.connections.mysql.database', 'standard_environment');
        config()->set('aninf.initial_admin_password', 'Initial1!');
        $direction = $this->configureInitialAdminDirection();

        $this->seed();

        $this->assertDatabaseCount('permissions', 67);
        $this->assertDatabaseCount('roles', 2);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'admin@aninf.ga', 'must_change_password' => true]);
        $this->assertDatabaseCount('directions', 1);
        $this->assertSame($direction->id, User::query()->firstOrFail()->direction_id);
        foreach (['types_sortie', 'entites_geographiques', 'demandes', 'chauffeurs', 'vehicules'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function configureTestSeeding(): void
    {
        $this->configureInitialAdminDirection();
        config()->set('database.connections.mysql.database', TestDataSeeder::TEST_DATABASE);
        config()->set('aninf.initial_admin_password', 'Initial1!');
    }
}
