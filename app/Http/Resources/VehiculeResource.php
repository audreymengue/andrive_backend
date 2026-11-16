<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehiculeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'immatriculation' => $this->immatriculation,
            'places_utiles' => (int) $this->places_utiles,
            'statut' => $this->statut,
            'lock_version' => (int) $this->lock_version,
        ];
    }
}
