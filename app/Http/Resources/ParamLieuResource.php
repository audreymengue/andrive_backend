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

        return [
            'id' => (int) $entite->id,
            'adresse' => $entite->adresse,
            'latitude' => $entite->latitude,
            'longitude' => $entite->longitude,
            'description' => $entite->description,
            'statut' => $entite->statut,
            'utilise' => $entite->usage_observe_at !== null,
            'lock_version' => (int) $entite->lock_version,
        ];
    }
}
