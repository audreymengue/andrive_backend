<?php

namespace App\Http\Resources;

use App\Models\RattachementDirection;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganisationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id];
        if ($this->resource instanceof Service) {
            $data['direction_id'] = $this->direction_id;
        }

        if ($this->resource->relationLoaded('rattachements')) {
            $data['rattachements'] = $this->rattachements
                ->map(fn (RattachementDirection $rattachement): array => $rattachement->only(['id', 'code', 'nom']))
                ->all();
        }

        return [...$data, 'nom' => $this->nom, 'code' => $this->code, 'description' => $this->description, 'statut' => $this->statut, 'lock_version' => $this->lock_version];
    }
}
