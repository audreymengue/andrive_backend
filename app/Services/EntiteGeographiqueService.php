<?php

namespace App\Services;

use App\Models\DemandeModification;
use App\Models\EntiteGeographique;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;

class EntiteGeographiqueService
{
    /**
     * @return Collection<int, EntiteGeographique>
     */
    public function list(): Collection
    {
        return EntiteGeographique::query()->orderBy('id')->get();
    }

    /**
     * @param  array{adresse: string, latitude?: numeric|null, longitude?: numeric|null, description?: string|null}  $data
     */
    public function create(array $data): EntiteGeographique
    {
        return EntiteGeographique::query()->create($data);
    }

    public function find(int $id): EntiteGeographique
    {
        return EntiteGeographique::query()->findOrFail($id);
    }

    /**
     * @param  array{adresse?: string, latitude?: numeric|null, longitude?: numeric|null, description?: string|null}  $data
     */
    public function update(EntiteGeographique $entiteGeographique, array $data): EntiteGeographique
    {
        $entiteGeographique->update($data);

        return $entiteGeographique;
    }

    public function delete(EntiteGeographique $entiteGeographique): void
    {
        if (
            $entiteGeographique->demandesAuDepart()->exists()
            || $entiteGeographique->demandesAuRetour()->exists()
            || $entiteGeographique->destinations()->exists()
            || $entiteGeographique->modificationsAuDepart()->exists()
            || $entiteGeographique->modificationsAuRetour()->exists()
            || DemandeModification::query()->select(['id', 'destinations'])->lazyById()->contains(
                fn (DemandeModification $modification): bool => collect($modification->destinations)->contains(
                    fn (array $destination): bool => (int) $destination['entite_geographique_id'] === $entiteGeographique->id,
                ),
            )
        ) {
            throw new HttpResponseException(response()->json([
                'message' => 'Cette entité géographique ne peut pas être supprimée car elle est utilisée par des demandes.',
            ], 409));
        }

        $entiteGeographique->delete();
    }
}
