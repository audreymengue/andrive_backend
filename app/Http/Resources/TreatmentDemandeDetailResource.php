<?php

namespace App\Http\Resources;

use App\Models\Demande;
use App\Models\DemandeDestination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Demande */
class TreatmentDemandeDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            'statut' => $this->statut,
            'date_depart' => $this->date_depart?->format('Y-m-d'),
            'heure_depart' => $this->heure_depart,
            'heure_retour' => $this->heure_retour,
            'adresse_depart' => $this->adresseDepart?->only(['id', 'adresse']),
            'adresse_retour' => $this->adresseRetour?->only(['id', 'adresse']),
            'destinations' => $this->destinations->map(fn (DemandeDestination $destination): array => [
                'ordre' => (int) $destination->ordre,
                'entite_geographique' => $destination->entiteGeographique?->only(['id', 'adresse']),
            ])->all(),
            'employes' => $this->employes->map->only(['id', 'nom', 'prenom'])->all(),
            'objets' => $this->objets->map->only(['id', 'designation', 'quantite'])->all(),
        ];
    }
}
