<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemandeResource extends JsonResource
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
            'reference' => $this->reference,
            'chauffeur_telephone' => $this->whenHas('chauffeur_telephone'),
            'demandeur' => $this->whenLoaded('demandeur', fn (): array => [
                'id' => $this->demandeur->id,
                'nom' => $this->demandeur->nom,
                'prenom' => $this->demandeur->prenom,
            ]),
            'direction' => $this->whenLoaded('direction', fn (): array => [
                'id' => $this->direction->id,
                'nom' => $this->direction->nom,
            ]),
            'service' => $this->whenLoaded('service', fn (): ?array => $this->service === null ? null : [
                'id' => $this->service->id,
                'nom' => $this->service->nom,
                'code' => $this->service->code,
            ]),
            'type_sortie' => $this->whenLoaded('typeSortie', fn (): array => [
                'id' => $this->typeSortie->id,
                'libelle' => $this->typeSortie->libelle,
                'code' => $this->typeSortie->code,
            ]),
            'adresse_depart' => $this->whenLoaded('adresseDepart', fn (): array => [
                'id' => $this->adresseDepart->id,
                'adresse' => $this->adresseDepart->adresse,
                'latitude' => $this->adresseDepart->latitude,
                'longitude' => $this->adresseDepart->longitude,
                'description' => $this->adresseDepart->description,
            ]),
            'adresse_retour' => $this->whenLoaded('adresseRetour', fn (): array => [
                'id' => $this->adresseRetour->id,
                'adresse' => $this->adresseRetour->adresse,
                'latitude' => $this->adresseRetour->latitude,
                'longitude' => $this->adresseRetour->longitude,
                'description' => $this->adresseRetour->description,
            ]),
            'date_depart' => $this->date_depart?->format('Y-m-d'),
            'heure_depart' => $this->heure_depart,
            'heure_retour' => $this->heure_retour,
            'motif' => $this->motif,
            'commentaire' => $this->commentaire,
            'statut' => $this->statut,
            'destinations' => $this->whenLoaded('destinations', fn (): array => $this->destinations
                ->map(fn ($destination): array => [
                    'ordre' => $destination->ordre,
                    'entite_geographique' => $destination->entiteGeographique === null ? null : [
                        'id' => $destination->entiteGeographique->id,
                        'adresse' => $destination->entiteGeographique->adresse,
                        'latitude' => $destination->entiteGeographique->latitude,
                        'longitude' => $destination->entiteGeographique->longitude,
                        'description' => $destination->entiteGeographique->description,
                    ],
                ])
                ->all()),
            'employes' => $this->whenLoaded('employes', fn (): array => $this->employes
                ->map(fn ($employe): array => [
                    'nom' => $employe->nom,
                    'prenom' => $employe->prenom,
                    'fonction' => $employe->fonction,
                ])
                ->all()),
            'objets' => $this->whenLoaded('objets', fn (): array => $this->objets
                ->map(fn ($objet): array => [
                    'designation' => $objet->designation,
                    'quantite' => $objet->quantite,
                    'description' => $objet->description,
                ])
                ->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
