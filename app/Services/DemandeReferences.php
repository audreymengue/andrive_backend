<?php

namespace App\Services;

use App\Models\Demande;
use App\Models\DemandeModification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DemandeReferences
{
    private const HEADER_FIELDS = ['direction_id', 'service_id', 'type_sortie_id', 'adresse_depart_id', 'adresse_retour_id'];

    public static function lockGuard(): void
    {
        if (DB::table('organisation_guards')->where('id', 1)->sharedLock()->first() === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'La garde des référentiels est indisponible.',
                'code' => 'TEMPORARILY_UNAVAILABLE',
            ], 503));
        }
    }

    /** @return array<string, mixed> */
    public static function selection(Demande|DemandeModification $source): array
    {
        $data = $source->only(self::HEADER_FIELDS);
        $data['destinations'] = $source instanceof Demande
            ? $source->destinations->map->only(['ordre', 'entite_geographique_id'])->all()
            : $source->destinations;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $previous
     * @return array<string, string>
     */
    public static function invalidSelections(array $data, array $previous = [], bool $lock = false): array
    {
        $errors = [];
        $directionId = (int) $data['direction_id'];
        $serviceId = (int) ($data['service_id'] ?? 0);
        $newDirection = $directionId !== (int) ($previous['direction_id'] ?? 0);
        $newService = $serviceId !== 0 && ($newDirection || $serviceId !== (int) ($previous['service_id'] ?? 0));
        if ($newDirection || $newService) {
            $direction = DB::table('directions')->where('id', $directionId)->when($lock, fn ($query) => $query->sharedLock())->first(['statut']);
            if ($direction?->statut !== 'ACTIF') {
                $errors['direction_id'] = 'La direction sélectionnée doit être active.';
            }
        }
        if ($newService) {
            $service = DB::table('services')->where('id', $serviceId)->when($lock, fn ($query) => $query->sharedLock())->first(['direction_id', 'statut']);
            if ((int) ($service->direction_id ?? 0) !== $directionId || $service->statut !== 'ACTIF') {
                $errors['service_id'] = 'Le service doit être actif et appartenir à la direction sélectionnée.';
            }
        }

        $type = (int) $data['type_sortie_id'];
        if ($type !== (int) ($previous['type_sortie_id'] ?? 0)) {
            $row = DB::table('types_sortie')->where('id', $type)->when($lock, fn ($query) => $query->lockForUpdate())->first(['statut']);
            if ($row?->statut !== 'ACTIF') {
                $errors['type_sortie_id'] = 'Le type de sortie sélectionné doit être actif.';
            }
        }

        $places = [];
        foreach (['adresse_depart_id', 'adresse_retour_id'] as $field) {
            $id = (int) ($data[$field] ?? 0);
            if ($id !== 0 && $id !== (int) ($previous[$field] ?? 0)) {
                $places[$field] = $id;
            }
        }
        $retained = array_count_values(array_map('intval', array_column($previous['destinations'] ?? [], 'entite_geographique_id')));
        foreach ($data['destinations'] as $index => $destination) {
            $id = (int) $destination['entite_geographique_id'];
            if (($retained[$id] ?? 0) > 0) {
                $retained[$id]--;
            } else {
                $places["destinations.$index.entite_geographique_id"] = $id;
            }
        }
        if ($places !== []) {
            $statuses = DB::table('entites_geographiques')->whereIn('id', array_unique($places))->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->pluck('statut', 'id');
            foreach ($places as $field => $id) {
                if ($statuses->get($id) !== 'ACTIF') {
                    $errors[$field] = 'Le lieu sélectionné doit être actif.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $previous
     */
    public static function ensureActive(array $data, array $previous = []): void
    {
        $errors = self::invalidSelections($data, $previous, lock: true);
        if ($errors !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Une ou plusieurs références sont indisponibles. Corrigez les champs indiqués.',
                'code' => 'REFERENCE_INACTIVE',
                'errors' => array_map(fn (string $message): array => [$message], $errors),
            ], 409));
        }
    }

    /** @param array<string, mixed> $data */
    public static function recordUsage(array $data): void
    {
        $observation = ['usage_observe_at' => now(), 'origine_usage' => 'enregistrement'];
        if (isset($data['type_sortie_id'])) {
            DB::table('types_sortie')->where('id', $data['type_sortie_id'])->whereNull('usage_observe_at')->update($observation);
        }
        $places = array_filter([
            $data['adresse_depart_id'] ?? null,
            $data['adresse_retour_id'] ?? null,
            ...array_column($data['destinations'] ?? [], 'entite_geographique_id'),
        ]);
        if ($places !== []) {
            DB::table('entites_geographiques')->whereIntegerInRaw('id', array_unique($places))->whereNull('usage_observe_at')->update($observation);
        }
    }
}
