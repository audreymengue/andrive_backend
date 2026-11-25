<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChauffeurResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->relationLoaded('user') ? $this->user : null;

        return [
            'id' => (int) $this->id,
            'user_id' => $this->user_id === null ? null : (int) $this->user_id,
            'utilisateur' => $user === null ? null : [
                'id' => (int) $user->id,
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'statut' => $user->statut,
            ],
            'nom_complet' => $this->nom_complet,
            'mention_pool' => $this->mention_pool,
            'statut' => $this->statut,
            'lock_version' => (int) $this->lock_version,
        ];
    }
}
