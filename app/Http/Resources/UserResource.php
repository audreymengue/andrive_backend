<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'email' => $this->email,
            'statut' => $this->statut,
            'must_change_password' => $this->must_change_password,
            'direction_id' => $this->direction_id,
            'service_id' => $this->service_id,
            'lock_version' => (int) $this->lock_version,
            'password_generation' => (int) $this->password_generation,
            'temporary_password_available' => $this->must_change_password && $this->resource->temporary_password !== null,
            'permissions_effectives' => $this->resource->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'permissions_directes_legacy' => $this->resource->permissions()->orderBy('name')->pluck('name')->all(),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
        ];
    }
}
