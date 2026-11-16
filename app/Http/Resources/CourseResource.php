<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $preparations = $this->whenLoaded('preparations', fn () => $this->preparations->map(fn ($preparation): array => [
            'version' => (int) $preparation->version,
            'actor_id' => (int) $preparation->actor_id,
            'chauffeur_id' => $preparation->chauffeur_id === null ? null : (int) $preparation->chauffeur_id,
            'vehicule_id' => $preparation->vehicule_id === null ? null : (int) $preparation->vehicule_id,
            'plan' => $preparation->plan,
            'demande_ids' => $preparation->demandes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'created_at' => $preparation->created_at?->toISOString(),
        ])->values()->all());
        $current = $this->whenLoaded('currentPreparation', fn () => $this->currentPreparation);

        return [
            'id' => (int) $this->id,
            'statut_execution' => $this->statut_execution,
            'lock_version' => (int) $this->lock_version,
            'version_preparation' => (int) ($current?->version ?? 1),
            'demande_ids' => $current ? $current->demandes->pluck('id')->map(fn ($id): int => (int) $id)->all() : [],
            'chauffeur_id' => $current?->chauffeur_id === null ? null : (int) $current->chauffeur_id,
            'vehicule_id' => $current?->vehicule_id === null ? null : (int) $current->vehicule_id,
            'plan' => $current?->plan,
            'demarree_a' => $this->demarree_a?->toISOString(),
            'terminee_a' => $this->terminee_a?->toISOString(),
            'chauffeur_libere_a' => $this->chauffeur_libere_a?->toISOString(),
            'vehicule_libere_a' => $this->vehicule_libere_a?->toISOString(),
            'preparations' => $preparations,
            'created_by_id' => (int) $this->created_by_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
