<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntiteGeographiqueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adresse' => $this->adresse,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'description' => $this->description,
        ];
    }
}
