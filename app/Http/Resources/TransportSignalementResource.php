<?php

namespace App\Http\Resources;

use App\Models\SignalementTransport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SignalementTransport */
class TransportSignalementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => $this->type,
            'type_ressource' => $this->vehicule_id === null ? 'CHAUFFEUR' : 'VEHICULE',
            'ressource_id' => (int) ($this->vehicule_id ?? $this->chauffeur_id),
            'course_id' => $this->course_id === null ? null : (int) $this->course_id,
            'details' => $this->details,
            'signale_a' => $this->signale_a?->toISOString(),
        ];
    }
}
