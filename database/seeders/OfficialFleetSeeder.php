<?php

namespace Database\Seeders;

use App\Models\Chauffeur;
use App\Models\Vehicule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class OfficialFleetSeeder extends Seeder
{
    public static function defaultPath(): string
    {
        return storage_path('app/private/parc-officiel.json');
    }

    public function run(?string $path = null): void
    {
        $source = $this->readSource($path ?? self::defaultPath());

        try {
            [$driversAdded, $vehiclesAdded, $conflictingVehicles] = DB::transaction(function () use ($source): array {
                if (DB::table('organisation_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    throw new RuntimeException('La garde organisationnelle est indisponible.');
                }

                $driversAdded = $this->importDrivers($source['chauffeurs']);

                [$vehiclesAdded, $conflictingVehicles] = $this->importVehicles($source['vehicules']);

                return [$driversAdded, $vehiclesAdded, $conflictingVehicles];
            }, 3);
        } catch (QueryException) {
            throw new RuntimeException('L’import du parc a été annulé après une erreur de base de données.');
        }

        $this->command?->info("Parc officiel : {$driversAdded} chauffeur(s) et {$vehiclesAdded} véhicule(s) ajoutés.");
        if ($conflictingVehicles > 0) {
            $this->command?->warn("{$conflictingVehicles} véhicule(s) présentent un écart avec la source. Profils existants conservés, à vérifier.");
        }
    }

    /**
     * @return array{chauffeurs: list<array{reference: string, nom_complet: string, telephone: ?string, mention_pool: ?string}>, vehicules: list<array{immatriculation: string, marque: string, modele: string, nombre_places: int, statut: string}>}
     */
    private function readSource(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Le fichier privé du parc officiel est absent ou illisible.');
        }
        try {
            $source = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Le fichier privé du parc officiel contient du JSON invalide.');
        }

        $validator = Validator::make(['source' => $source], [
            'source' => ['required', 'array:chauffeurs,vehicules'],
            'source.chauffeurs' => ['present', 'array', 'list'],
            'source.chauffeurs.*' => ['required', 'array:reference,nom_complet,telephone,mention_pool'],
            'source.chauffeurs.*.reference' => ['required', 'string', 'max:255'],
            'source.chauffeurs.*.nom_complet' => ['required', 'string', 'max:255'],
            'source.chauffeurs.*.telephone' => ['present', 'nullable', 'string', 'max:32', 'regex:/^\+?(?=[0-9 ().-]*[0-9])[0-9 ().-]+$/D'],
            'source.chauffeurs.*.mention_pool' => ['present', 'nullable', 'string', 'max:255'],
            'source.vehicules' => ['present', 'array', 'list'],
            'source.vehicules.*' => ['required', 'array:immatriculation,marque,modele,nombre_places,statut'],
            'source.vehicules.*.immatriculation' => ['required', 'string', 'max:255'],
            'source.vehicules.*.marque' => ['required', 'string', 'max:255'],
            'source.vehicules.*.modele' => ['required', 'string', 'max:255'],
            'source.vehicules.*.nombre_places' => ['required', 'integer:strict', 'min:2', 'max:4294967295'],
            'source.vehicules.*.statut' => ['required', 'in:ACTIF,INACTIF'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('La structure ou une valeur du fichier privé du parc officiel est invalide.');
        }

        $references = [];
        $names = [];
        foreach ($source['chauffeurs'] as $index => $driver) {
            $reference = mb_strtoupper(trim($driver['reference']));
            $name = $this->identityKey($driver['nom_complet']);
            if ($name === '' || isset($references[$reference]) || isset($names[$name])) {
                throw new RuntimeException('Identité ou référence chauffeur ambiguë à la ligne '.($index + 1).'.');
            }
            $references[$reference] = true;
            $names[$name] = true;
        }

        $plates = [];
        foreach ($source['vehicules'] as $index => $vehicle) {
            $plate = $this->plateKey($vehicle['immatriculation']);
            if ($plate === '' || isset($plates[$plate])) {
                throw new RuntimeException('Immatriculation vide ou en double à la ligne '.($index + 1).'.');
            }
            $plates[$plate] = true;
        }

        return $source;
    }

    /** @param list<array{reference: string, nom_complet: string, telephone: ?string, mention_pool: ?string}> $drivers */
    private function importDrivers(array $drivers): int
    {
        $references = [];
        $existingNames = [];
        $profiles = Chauffeur::query()->with('user:id,nom,prenom,name')->get(['user_id', 'reference_source', 'nom_complet']);
        foreach ($profiles as $profile) {
            if ($profile->reference_source !== null) {
                $references[mb_strtoupper(trim($profile->reference_source))] = true;
            }
            if ($profile->nom_complet !== null) {
                $existingNames[$this->identityKey($profile->nom_complet)] = true;
            }
            if ($profile->user !== null) {
                $existingNames[$this->identityKey($profile->user->nom.' '.$profile->user->prenom)] = true;
                if ($profile->user->name !== null) {
                    $existingNames[$this->identityKey($profile->user->name)] = true;
                }
            }
        }

        $added = 0;
        foreach ($drivers as $index => $driver) {
            $reference = mb_strtoupper(trim($driver['reference']));
            if (isset($references[$reference])) {
                continue;
            }
            if (isset($existingNames[$this->identityKey($driver['nom_complet'])])) {
                throw new RuntimeException('Une identité chauffeur existe déjà sous une autre référence ou sans référence, ligne '.($index + 1).'. Rapprochement manuel requis.');
            }

            $profile = new Chauffeur;
            $profile->reference_source = $reference;
            $profile->nom_complet = trim($driver['nom_complet']);
            $profile->telephone = $driver['telephone'];
            $profile->mention_pool = $driver['mention_pool'];
            $profile->statut = 'ACTIF';
            $profile->save();
            $added++;
        }

        return $added;
    }

    /**
     * @param  list<array{immatriculation: string, marque: string, modele: string, nombre_places: int, statut: string}>  $vehicles
     * @return array{int, int}
     */
    private function importVehicles(array $vehicles): array
    {
        $profiles = Vehicule::query()->get(['id', 'immatriculation', 'immatriculation_source', 'marque', 'modele', 'places_utiles', 'statut']);
        $bySource = $profiles->whereNotNull('immatriculation_source')->keyBy('immatriculation_source');
        $byPlate = $profiles->groupBy(fn (Vehicule $profile): string => $this->plateKey($profile->immatriculation));

        $added = 0;
        $conflicts = 0;
        foreach ($vehicles as $index => $vehicle) {
            $plate = mb_strtoupper(trim($vehicle['immatriculation']));
            $key = $this->plateKey($plate);
            $originalProfile = $bySource->get($key);
            $matches = $byPlate->get($key);
            $plateProfile = $matches?->first();
            if (
                ($matches !== null && $matches->count() > 1)
                || ($plateProfile !== null && $plateProfile->immatriculation_source !== null && $plateProfile->immatriculation_source !== $key)
                || ($originalProfile !== null && $plateProfile !== null && ! $originalProfile->is($plateProfile))
            ) {
                throw new RuntimeException('Plusieurs profils correspondent au véhicule de la ligne '.($index + 1).'. Rapprochement manuel requis.');
            }
            $attributes = [
                'marque' => trim($vehicle['marque']),
                'modele' => trim($vehicle['modele']),
                'places_utiles' => $vehicle['nombre_places'] - 1,
                'statut' => $vehicle['statut'],
            ];
            $existing = $originalProfile ?? $plateProfile;
            if ($existing !== null) {
                if ($existing->immatriculation_source === null) {
                    if ($vehicle['statut'] === 'INACTIF' && $existing->statut === 'ACTIF') {
                        throw new RuntimeException('Véhicule hors service dans la source mais actif en base, ligne '.($index + 1).'. Vérifier son état et le désactiver via le Paramétrage avant de relancer l’import.');
                    }
                    $existing->immatriculation_source = $key;
                    $existing->timestamps = false;
                    $existing->save();
                }
                if ($this->plateKey($existing->immatriculation) !== $key || $existing->only(array_keys($attributes)) !== $attributes) {
                    $conflicts++;
                }

                continue;
            }

            $profile = new Vehicule(['immatriculation' => $plate, ...$attributes]);
            $profile->immatriculation_source = $key;
            $profile->save();
            $added++;
        }

        return [$added, $conflicts];
    }

    private function identityKey(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($name)), flags: PREG_SPLIT_NO_EMPTY);
        sort($words);

        return implode(' ', $words);
    }

    private function plateKey(string $plate): string
    {
        return preg_replace('/[\s.\p{Pd}]+/u', '', mb_strtoupper(trim($plate)));
    }
}
