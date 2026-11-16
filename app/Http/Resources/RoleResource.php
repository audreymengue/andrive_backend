<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
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
            'nom' => $this->name,
            'description' => $this->description,
            'statut' => $this->statut,
            'systeme' => $this->system_key !== null,
            'lock_version' => (int) $this->lock_version,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
