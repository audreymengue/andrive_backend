<?php

namespace Tests\Feature;

use App\Models\Chauffeur;
use App\Models\User;
use App\Models\Vehicule;
use Database\Seeders\OfficialFleetSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('mysql')]
class OfficialFleetSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new RuntimeException('Les tests du parc officiel exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new RuntimeException('Le serveur n’a pas sélectionné aninf_test.');
        }

        return $app;
    }

    public function test_imports_drivers_without_accounts_and_replays_without_overwriting_operational_changes(): void
    {
        $data = $this->manifest();
        $userCount = User::query()->count();

        $this->import($data);

        $driver = Chauffeur::query()->where('reference_source', 'QA-FLEET-A')->firstOrFail();
        $vehicle = Vehicule::query()->where('immatriculation', 'TEST-FLEET-01')->firstOrFail();
        $this->assertDatabaseHas('chauffeurs', [
            'id' => $driver->id, 'user_id' => null, 'nom_complet' => 'Pilote Fictif Alpha',
            'telephone' => '+1 202-555-0101', 'mention_pool' => 'Pool de test',
        ]);
        $this->assertDatabaseHas('chauffeurs', ['reference_source' => 'QA-FLEET-B', 'user_id' => null, 'telephone' => null]);
        $this->assertSame($userCount, User::query()->count());
        $this->assertDatabaseHas('vehicules', [
            'id' => $vehicle->id, 'marque' => 'Marque Test', 'modele' => 'Modèle A',
            'places_utiles' => 4, 'statut' => 'ACTIF',
        ]);
        $this->assertDatabaseHas('vehicules', ['immatriculation' => 'TEST-FLEET-02', 'places_utiles' => 2, 'statut' => 'INACTIF']);

        $driver->update(['telephone' => '+1 202-555-0109', 'statut' => 'INACTIF']);
        $vehicle->update(['immatriculation' => 'TEST-REPLATE-01', 'statut' => 'INACTIF', 'places_utiles' => 3]);
        $repaired = Vehicule::query()->where('immatriculation', 'TEST-FLEET-02')->firstOrFail();
        $repaired->update(['statut' => 'ACTIF']);
        $this->import($data);

        $this->assertDatabaseHas('chauffeurs', ['id' => $driver->id, 'reference_source' => 'QA-FLEET-A', 'telephone' => '+1 202-555-0109', 'statut' => 'INACTIF']);
        $this->assertDatabaseHas('vehicules', ['id' => $vehicle->id, 'immatriculation' => 'TEST-REPLATE-01', 'places_utiles' => 3, 'statut' => 'INACTIF']);
        $this->assertDatabaseHas('vehicules', ['id' => $repaired->id, 'immatriculation' => 'TEST-FLEET-02', 'statut' => 'ACTIF']);
        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'TEST-FLEET-01']);
        $this->assertSame(2, Chauffeur::query()->whereIn('reference_source', ['QA-FLEET-A', 'QA-FLEET-B'])->count());
        $this->assertSame(2, Vehicule::query()->whereIn('immatriculation', ['TEST-REPLATE-01', 'TEST-FLEET-02'])->count());
    }

    public function test_rejects_a_vehicle_without_any_passenger_seat_before_writing_the_manifest(): void
    {
        $data = $this->manifest();
        $data['vehicules'][1]['nombre_places'] = 1;

        try {
            $this->import($data);
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('La structure ou une valeur', $error->getMessage());
            $this->assertStringNotContainsString($data['vehicules'][1]['immatriculation'], $error->getMessage());
            $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'TEST-FLEET-01']);
            $this->assertDatabaseMissing('chauffeurs', ['reference_source' => 'QA-FLEET-A']);

            return;
        }

        $this->fail('Un véhicule sans place passager a été accepté.');
    }

    public function test_rejects_duplicate_plates_without_partially_importing_the_roster(): void
    {
        $data = $this->manifest();
        $data['vehicules'][1]['immatriculation'] = 'test fleet 01';

        try {
            $this->import($data);
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('en double', $error->getMessage());
            $this->assertStringNotContainsString($data['chauffeurs'][0]['telephone'], $error->getMessage());
            $this->assertDatabaseMissing('chauffeurs', ['reference_source' => 'QA-FLEET-A']);
            $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'TEST-FLEET-01']);

            return;
        }

        $this->fail('Deux écritures de la même plaque ont été acceptées.');
    }

    public function test_an_active_manual_vehicle_cannot_be_matched_automatically_to_an_out_of_service_vehicle(): void
    {
        $data = $this->manifest();
        $manual = Vehicule::factory()->create([
            'immatriculation' => $data['vehicules'][1]['immatriculation'],
            'statut' => 'ACTIF',
        ]);

        try {
            $this->import($data);
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString($manual->immatriculation, $error->getMessage());
            $this->assertDatabaseHas('vehicules', [
                'id' => $manual->id, 'immatriculation' => $manual->immatriculation,
                'statut' => 'ACTIF', 'immatriculation_source' => null,
            ]);
            $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'TEST-FLEET-01']);
            $this->assertDatabaseMissing('chauffeurs', ['reference_source' => 'QA-FLEET-A']);

            return;
        }

        $this->fail('Un véhicule hors service a été associé à un profil actif sans rapprochement manuel.');
    }

    public function test_a_matching_manual_driver_prevents_partial_import_and_keeps_the_account_link(): void
    {
        $data = $this->manifest();
        $existing = Chauffeur::factory()->create(['nom_complet' => $data['chauffeurs'][1]['nom_complet']]);

        try {
            $this->import($data);
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Rapprochement manuel requis', $error->getMessage());
            $this->assertStringNotContainsString($data['chauffeurs'][1]['nom_complet'], $error->getMessage());
            $this->assertDatabaseHas('chauffeurs', ['id' => $existing->id, 'user_id' => $existing->user_id, 'reference_source' => null]);
            $this->assertDatabaseMissing('chauffeurs', ['reference_source' => 'QA-FLEET-A']);
            $this->assertDatabaseMissing('chauffeurs', ['reference_source' => 'QA-FLEET-B']);
            $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'TEST-FLEET-01']);

            return;
        }

        $this->fail('Le profil manuel homonyme a été ignoré ou remplacé.');
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'chauffeurs' => [
                ['reference' => 'QA-FLEET-A', 'nom_complet' => 'Pilote Fictif Alpha', 'telephone' => '+1 202-555-0101', 'mention_pool' => 'Pool de test'],
                ['reference' => 'QA-FLEET-B', 'nom_complet' => 'Pilote Fictif Bêta', 'telephone' => null, 'mention_pool' => null],
            ],
            'vehicules' => [
                ['immatriculation' => 'TEST-FLEET-01', 'marque' => 'Marque Test', 'modele' => 'Modèle A', 'nombre_places' => 5, 'statut' => 'ACTIF'],
                ['immatriculation' => 'TEST-FLEET-02', 'marque' => 'Marque Test', 'modele' => 'Modèle B', 'nombre_places' => 3, 'statut' => 'INACTIF'],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function import(array $data): void
    {
        $disk = Storage::fake('local');
        $disk->put('parc-test.json', json_encode($data, JSON_THROW_ON_ERROR));
        app(OfficialFleetSeeder::class)->run($disk->path('parc-test.json'));
    }
}
