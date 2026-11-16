<?php

namespace App\Http\Resources;

use App\Models\IndisponibiliteRessource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IndisponibiliteRessource */
class ResourceAvailabilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type_ressource' => $this->vehicule_id === null ? 'CHAUFFEUR' : 'VEHICULE',
            'ressource_id' => (int) ($this->vehicule_id ?? $this->chauffeur_id),
            'debut_a' => $this->debut_a?->toISOString(),
            'fin_estimee_a' => $this->fin_estimee_a?->toISOString(),
            'fin_effective_a' => $this->fin_effective_a?->toISOString(),
            'motif' => $this->motif,
            'lock_version' => (int) $this->lock_version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
