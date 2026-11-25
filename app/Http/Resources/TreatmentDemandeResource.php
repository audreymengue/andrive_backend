<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreatmentDemandeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            'statut' => $this->statut,
            'direction' => ['id' => (int) $this->direction->id, 'nom' => $this->direction->nom],
            'service' => $this->service === null ? null : ['id' => (int) $this->service->id, 'nom' => $this->service->nom, 'code' => $this->service->code],
            'rattachement_direction' => $this->whenLoaded('rattachementDirection', fn (): array => [
                'id' => (int) $this->rattachementDirection->id,
                'nom' => $this->rattachementDirection->nom,
                'code' => $this->rattachementDirection->code,
            ]),
            'date_depart' => $this->date_depart?->format('Y-m-d'),
            'heure_depart' => $this->heure_depart,
            'personnes_count' => (int) ($this->employes_count ?? 0),
            'objets_count' => (int) ($this->objets_count ?? 0),
        ];
    }
}
