<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\DemandeModification;
use App\Models\DemandeReferenceSequence;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TypeSortie;
use App\Models\User;
use App\Services\ParametrageSecurity;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\CreatesParametrageFixtures;
use Tests\TestCase;

class TestDataSeederTest extends TestCase
{
    use CreatesParametrageFixtures;
    use LazilyRefreshDatabase;

    #[Test]
    public function it_refuses_to_seed_when_the_mysql_database_is_not_aninf_test(): void
    {
        config()->set('database.connections.mysql.database', 'aninf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Le seeder de test ne peut être exécuté que sur la base aninf_test.');

        $this->seed(TestDataSeeder::class);

        $this->assertSame(0, Direction::query()->count());
    }

    #[Test]
    public function it_creates_an_idempotent_and_coherent_test_data_set(): void
    {
        $this->travelTo('2026-09-29 09:00:00');

        $this->seedTestData();
        $this->seedTestData();

        $this->assertSame(4, Direction::query()->count());
        $this->assertDatabaseHas('directions', ['nom' => 'Direction des Services Informatiques', 'code' => 'DSI', 'statut' => 'ACTIF']);
        $this->assertDatabaseHas('directions', ['nom' => 'Direction Administrative et Financière', 'code' => 'DAF', 'statut' => 'ACTIF']);
        $this->assertDatabaseHas('directions', ['nom' => 'Direction des Ressources Humaines', 'code' => 'DRH', 'statut' => 'ACTIF']);

        $this->assertSame(6, User::query()->count());
        $this->assertDatabaseHas('users', ['email' => 'demandeur1@aninf.ga', 'direction_id' => Direction::query()->where('code', 'DSI')->value('id'), 'must_change_password' => false]);
        $this->assertDatabaseHas('users', ['email' => 'demandeur2@aninf.ga', 'direction_id' => Direction::query()->where('code', 'DAF')->value('id'), 'must_change_password' => false]);
        $this->assertDatabaseHas('users', ['email' => 'demandeur3@aninf.ga', 'direction_id' => Direction::query()->where('code', 'DRH')->value('id'), 'must_change_password' => false]);
        $this->assertDatabaseHas('users', ['email' => 'inactif-test@aninf.ga', 'statut' => 'INACTIF']);
        $this->assertTrue(Hash::check('TestPass1!', User::query()->where('email', 'admin-test@aninf.ga')->value('password')));

        $this->assertSame(2, Role::query()->count());
        $this->assertSame(59, Permission::query()->count());
        $this->assertTrue(User::query()->where('email', 'admin-test@aninf.ga')->firstOrFail()->hasRole('super_admin'));
        $this->assertTrue(User::query()->where('email', 'demandeur1@aninf.ga')->firstOrFail()->hasRole('demandeur'));
        $this->assertSame([
            'cancel_demande',
            'create_demande',
            'read_demande',
            'read_direction',
            'read_entite_geographique',
            'read_service',
            'read_type_sortie',
            'submit_demande',
            'update_demande',
        ], Role::query()->where('name', 'demandeur')->firstOrFail()->permissions()->pluck('name')->sort()->values()->all());

        $this->assertSame(12, Demande::query()->count());
        $this->assertSame(3, Demande::query()->where('statut', Demande::STATUT_BROUILLON)->count());
        $this->assertSame(3, Demande::query()->where('statut', Demande::STATUT_EN_ATTENTE)->count());
        $this->assertSame(2, Demande::query()->where('statut', Demande::STATUT_VALIDEE)->count());
        $this->assertSame(1, Demande::query()->where('statut', Demande::STATUT_REVALIDATION_REQUISE)->count());
        $this->assertSame(1, Demande::query()->where('statut', Demande::STATUT_REFUSEE)->count());
        $this->assertSame(1, Demande::query()->where('statut', Demande::STATUT_ANNULEE)->count());
        $this->assertSame(1, Demande::query()->where('statut', Demande::STATUT_TERMINEE)->count());

        $references = Demande::query()->pluck('reference');
        $this->assertSame(12, $references->unique()->count());

        foreach ($references as $reference) {
            $this->assertSame(1, preg_match(Demande::REFERENCE_PATTERN, $reference));
        }

        $this->assertSame(1, Demande::query()->where('reference', '29-09-26-0001')->count());
        $this->assertSame(1, Demande::query()->where('reference', '29-09-26-0012')->count());

        $this->assertDatabaseHas('demande_reference_sequences', ['reference_date' => '2026-09-29', 'last_sequence' => 12]);
        $this->assertDatabaseHas('demande_historiques', [
            'demande_id' => Demande::query()->where('reference', '29-09-26-0008')->value('id'),
            'ancien_statut' => Demande::STATUT_VALIDEE,
            'nouveau_statut' => Demande::STATUT_REVALIDATION_REQUISE,
        ]);
        $this->assertDatabaseHas('demande_modifications', [
            'demande_id' => Demande::query()->where('reference', '29-09-26-0006')->value('id'),
            'submitted_at' => null,
        ]);
        $this->assertSame(1, DemandeModification::query()->count());
    }

    #[Test]
    public function it_generates_the_next_reference_without_a_collision_after_seeding(): void
    {
        $this->travelTo('2026-09-29 09:00:00');
        $this->seedTestData();
        $user = User::query()->where('email', 'demandeur1@aninf.ga')->firstOrFail();
        $token = $user->createToken('test-data-seeder')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/demandes', [
                'direction_id' => Direction::query()->where('code', 'DSI')->value('id'),
                'type_sortie_id' => TypeSortie::query()->firstOrFail()->id,
                'adresse_depart_id' => EntiteGeographique::query()->firstOrFail()->id,
                'date_depart' => '2026-10-05',
                'heure_depart' => '08:00:00',
                'heure_retour' => '16:00:00',
                'motif' => 'Nouvelle demande après le seeder.',
                'commentaire' => null,
                'destinations' => [['entite_geographique_id' => EntiteGeographique::query()->where('adresse', 'Akanda')->firstOrFail()->id, 'ordre' => 1]],
                'employes' => [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service']],
                'objets' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.reference', '29-09-26-0013');

        $this->assertDatabaseHas('demandes', ['reference' => '29-09-26-0013']);
        $this->assertSame(13, DemandeReferenceSequence::query()->where('reference_date', '2026-09-29')->value('last_sequence'));
    }

    #[Test]
    public function it_attaches_every_new_test_account_to_an_active_direction(): void
    {
        $this->seedTestData();

        $this->assertDatabaseCount('users', 6);
        foreach (User::query()->with('direction')->get() as $user) {
            $this->assertNotNull($user->direction, 'Chaque nouveau compte de test exige un rattachement valide.');
            $this->assertSame('ACTIF', $user->direction->statut);
        }
    }

    #[Test]
    public function it_bootstraps_test_data_without_a_preconfigured_initial_admin_direction(): void
    {
        config()->set('database.connections.mysql.database', TestDataSeeder::TEST_DATABASE);
        config()->set('aninf.initial_admin_password', 'Initial1!');
        config()->set('aninf.initial_admin_direction_id', null);

        $this->seed(TestDataSeeder::class);

        $direction = Direction::query()->where('code', 'DSI')->firstOrFail();
        $initialAdmin = User::query()->where('email', 'admin@aninf.ga')->firstOrFail();

        $this->assertSame($direction->id, $initialAdmin->direction_id);
        $this->assertSame(3, Direction::query()->count());
        $this->assertSame(6, User::query()->count());
    }

    #[Test]
    public function it_preserves_explicit_business_grants_when_reseeded(): void
    {
        $this->seedTestData();
        $admin = Role::query()->where('system_key', 'super_admin')->firstOrFail();
        $demandeur = Role::query()->where('system_key', 'demandeur')->firstOrFail();
        $custom = Permission::factory()->create(['name' => 'test_business_grant']);
        $admin->givePermissionTo('read_demande');
        $demandeur->givePermissionTo($custom);

        $this->seedTestData();

        $this->assertTrue($admin->fresh()->hasPermissionTo('read_demande'));
        $this->assertTrue($demandeur->fresh()->hasPermissionTo($custom));
        foreach (ParametrageSecurity::demandeurPermissions() as $permission) {
            $this->assertTrue($demandeur->fresh()->hasPermissionTo($permission));
        }
    }

    private function seedTestData(): void
    {
        if (config('aninf.initial_admin_direction_id') === null || ! Direction::query()->whereKey(config('aninf.initial_admin_direction_id'))->exists()) {
            $this->configureInitialAdminDirection();
        }
        config()->set('database.connections.mysql.database', TestDataSeeder::TEST_DATABASE);
        config()->set('aninf.initial_admin_password', 'Initial1!');

        $this->seed(TestDataSeeder::class);
    }
}
