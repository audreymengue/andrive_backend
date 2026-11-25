<?php

namespace App\Http\Requests;

use App\Models\Demande;
use App\Services\DemandeReferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class DemandePayloadRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function demandeRules(): array
    {
        return [
            'direction_id' => ['required', 'integer', 'min:1', Rule::exists('directions', 'id')],
            'service_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('services', 'id')],
            'rattachement_direction_id' => [
                'bail', 'sometimes', 'nullable', 'integer', 'min:1', 'prohibits:service_id',
                Rule::exists('rattachements_direction', 'id')->where('direction_id', $this->integer('direction_id')),
            ],
            'type_sortie_id' => ['required', 'integer', 'min:1', Rule::exists('types_sortie', 'id')],
            'adresse_depart_id' => ['required', 'integer', 'min:1', Rule::exists('entites_geographiques', 'id')],
            'adresse_retour_id' => ['nullable', 'integer', 'min:1', Rule::exists('entites_geographiques', 'id')],
            'date_depart' => ['required', 'date_format:Y-m-d'],
            'heure_depart' => ['required', 'date_format:H:i:s'],
            'heure_retour' => ['nullable', 'date_format:H:i:s'],
            'motif' => ['required', 'string'],
            'commentaire' => ['nullable', 'string'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.entite_geographique_id' => ['required', 'integer', 'min:1', Rule::exists('entites_geographiques', 'id')],
            'destinations.*.ordre' => ['required', 'integer', 'min:1', 'distinct'],
            'employes' => ['required', 'array', 'min:1'],
            'employes.*.nom' => ['required', 'string', 'max:255'],
            'employes.*.prenom' => ['required', 'string', 'max:255'],
            'employes.*.fonction' => ['nullable', 'string', 'max:255'],
            'objets' => ['nullable', 'array'],
            'objets.*.designation' => ['required', 'string', 'max:255'],
            'objets.*.quantite' => ['nullable', 'integer', 'min:1'],
            'objets.*.description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->hasAny(['heure_depart', 'heure_retour'])) {
                    $heureDepart = $this->input('heure_depart');
                    $heureRetour = $this->input('heure_retour');

                    if (is_string($heureDepart) && is_string($heureRetour) && $heureRetour <= $heureDepart) {
                        $validator->errors()->add(
                            'heure_retour',
                            'L’heure de retour doit être postérieure à l’heure de départ.',
                        );
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $previous = [];
                if ($this->route('id') !== null) {
                    $query = Demande::query()->whereKey($this->route('id'));
                    if (! $this->user()->hasRole('super_admin')) {
                        $query->where('demandeur_id', $this->user()->id);
                    }
                    $demande = $query->first();
                    $source = $this instanceof UpdateDemandeModificationRequest ? $demande?->modification : $demande;
                    if ($source !== null) {
                        $previous = DemandeReferences::selection($source);
                    }
                }

                foreach (DemandeReferences::invalidSelections($this->all(), $previous) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'rattachement_direction_id.prohibits' => 'Choisissez un service ou un rattachement de direction.',
            'rattachement_direction_id.exists' => 'Le rattachement doit appartenir à la direction sélectionnée.',
            'date_format' => 'Le champ :attribute doit respecter le format :format.',
            'regex' => 'Le champ :attribute est invalide.',
            'min.array' => 'Le champ :attribute doit contenir au moins :min élément.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'type_sortie_id' => 'type de sortie',
            'service_id' => 'service',
            'rattachement_direction_id' => 'rattachement de direction',
            'adresse_depart_id' => 'adresse de départ',
            'adresse_retour_id' => 'adresse de retour',
            'date_depart' => 'date de départ',
            'heure_depart' => 'heure de départ',
            'heure_retour' => 'heure de retour',
            'motif' => 'motif',
            'commentaire' => 'commentaire',
            'destinations' => 'destinations',
            'employes' => 'employés',
            'objets' => 'objets',
        ];
    }
}
