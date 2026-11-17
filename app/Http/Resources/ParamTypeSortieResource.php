<?php

namespace App\Http\Resources;

use App\Models\TypeSortie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParamTypeSortieResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TypeSortie $typeSortie */
        $typeSortie = $this->resource;
        $attributes = $typeSortie->getAttributes();
        $utilise = array_key_exists('demandes_exists', $attributes)
            ? (bool) $attributes['demandes_exists'] || (bool) $attributes['modifications_exists']
            : app(\App\Services\TypeSortieService::class)->isUsed($typeSortie);

        return [
            'id' => (int) $typeSortie->id,
            'code' => $typeSortie->code,
            'libelle' => $typeSortie->libelle,
            'statut' => $typeSortie->statut,
            'utilise' => (bool) $utilise,
            'lock_version' => (int) $typeSortie->lock_version,
        ];
    }
}
