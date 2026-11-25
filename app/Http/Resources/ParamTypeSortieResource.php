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

        return [
            'id' => (int) $typeSortie->id,
            'code' => $typeSortie->code,
            'libelle' => $typeSortie->libelle,
            'statut' => $typeSortie->statut,
            'utilise' => $typeSortie->usage_observe_at !== null,
            'lock_version' => (int) $typeSortie->lock_version,
        ];
    }
}
