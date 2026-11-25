<?php

namespace Database\Seeders;

use App\Models\Chauffeur;
use App\Models\Vehicule;
use App\Services\ParametrageSecurity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QaCourseResourcesSeeder extends Seeder
{
    public const string PLATE = 'QA-DEMO-01';

    public const string DRIVER_PHONE = '+1 202-555-0142';

    public function run(): void
    {
        TestDataSeeder::assertTestDatabase();

        DB::transaction(function (): void {
            app(ParametrageSecurity::class)->lock();
            $driver = Chauffeur::query()
                ->whereNull('reference_source')
                ->whereHas('user', fn ($users) => $users->where('email', 'qa.chauffeur@example.test'))
                ->lockForUpdate()
                ->first();

            if ($driver === null) {
                throw new RuntimeException('Le profil du chauffeur QA est requis. Initialisez les comptes de démonstration avant les ressources.');
            }

            if ($driver->telephone === null) {
                $driver->forceFill(['telephone' => self::DRIVER_PHONE, 'lock_version' => $driver->lock_version + 1])->save();
            }

            Vehicule::query()->firstOrCreate(['immatriculation' => self::PLATE], [
                'marque' => 'Marque QA',
                'modele' => 'Véhicule de démonstration',
                'places_utiles' => 4,
                'statut' => 'ACTIF',
            ]);
        }, 3);
    }
}
