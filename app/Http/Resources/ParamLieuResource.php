<?php

namespace App\Http\Resources;

use App\Models\EntiteGeographique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParamLieuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EntiteGeographique $entite */
        $entite = $this->resource;
        $attributes = $entite->getAttributes();
        $hasEagerUseFlags = array_key_exists('demandes_au_depart_exists', $attributes);
        $utilise = $hasEagerUseFlags
            ? (bool) $attributes['demandes_au_depart_exists']
                || (bool) $attributes['demandes_au_retour_exists']
                || (bool) $attributes['destinations_exists']
                || (bool) $attributes['modifications_au_depart_exists']
                || (bool) $attributes['modifications_au_retour_exists']
                || (bool) ($attributes['modification_destinations_exists'] ?? false)
            : app(\App\Services\EntiteGeographiqueService::class)->isUsed($entite);

        return [
            'id' => (int) $entite->id,
            'adresse' => $entite->adresse,
            'latitude' => $entite->latitude,
            'longitude' => $entite->longitude,
            'description' => $entite->description,
            'statut' => $entite->statut,
            'utilise' => (bool) $utilise,
            'lock_version' => (int) $entite->lock_version,
        ];
    }
}
