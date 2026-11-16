<?php

namespace App\Http\Requests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class UpdateCoursePreparationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'version_attendue' => ['required', 'integer', 'min:0'],
            'demande_ids' => ['present', 'array'],
            'demande_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'chauffeur_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'vehicule_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'plan' => ['sometimes', 'nullable', 'array'],
            'plan.occupation_debut_prevue' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'plan.occupation_fin_prevue' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'plan.chargement_confirme' => ['sometimes', 'nullable', 'boolean'],
            'plan.arrets' => ['sometimes', 'array'],
            'plan.arrets.*.ordre' => ['required', 'integer', 'min:1', 'distinct'],
            'plan.arrets.*.lieu' => ['required', 'string', 'min:1', 'max:255'],
            'plan.arrets.*.heure_prevue' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'plan.arrets.*.employes' => ['sometimes', 'array'],
            'plan.arrets.*.employes.*.demande_employe_id' => ['required', 'integer', 'min:1'],
            'plan.arrets.*.employes.*.action' => ['required', 'in:PRISE_EN_CHARGE,DEPOSE'],
            'plan.arrets.*.objets' => ['sometimes', 'array'],
            'plan.arrets.*.objets.*.demande_objet_id' => ['required', 'integer', 'min:1'],
            'plan.arrets.*.objets.*.action' => ['required', 'in:PRISE_EN_CHARGE,DEPOSE'],
        ];
    }

    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedFields = ['version_attendue', 'demande_ids', 'chauffeur_id', 'vehicule_id', 'plan'];
            foreach (array_diff(array_keys($this->all()), $allowedFields) as $field) {
                $validator->errors()->add($field, 'Ce champ ne peut pas être modifié par cette opération.');
            }

            $plan = $this->input('plan');
            if (! is_array($plan)) {
                return;
            }
            foreach (array_diff(array_keys($plan), ['occupation_debut_prevue', 'occupation_fin_prevue', 'chargement_confirme', 'arrets']) as $field) {
                $validator->errors()->add('plan.'.$field, 'Ce champ du plan n’est pas accepté.');
            }
            foreach ($plan['arrets'] ?? [] as $stopIndex => $stop) {
                if (! is_array($stop)) {
                    continue;
                }
                foreach (array_diff(array_keys($stop), ['ordre', 'lieu', 'heure_prevue', 'employes', 'objets']) as $field) {
                    $validator->errors()->add('plan.arrets.'.$stopIndex.'.'.$field, 'Ce champ de l’arrêt n’est pas accepté.');
                }
                foreach (['employes' => 'demande_employe_id', 'objets' => 'demande_objet_id'] as $collection => $identifier) {
                    foreach ($stop[$collection] ?? [] as $itemIndex => $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        foreach (array_diff(array_keys($item), [$identifier, 'action']) as $field) {
                            $validator->errors()->add('plan.arrets.'.$stopIndex.'.'.$collection.'.'.$itemIndex.'.'.$field, 'Ce champ de l’arrêt n’est pas accepté.');
                        }
                    }
                }
            }

            if ($validator->errors()->count() > 0) {
                return;
            }
            $occupationStart = $plan['occupation_debut_prevue'] ?? null;
            $occupationEnd = $plan['occupation_fin_prevue'] ?? null;
            if ($occupationStart !== null && $occupationEnd !== null && new \DateTimeImmutable($occupationEnd) <= new \DateTimeImmutable($occupationStart)) {
                $validator->errors()->add('plan.occupation_fin_prevue', 'La fin d’occupation doit être postérieure à son début.');
            }

            $stops = array_values(array_filter($plan['arrets'] ?? [], 'is_array'));
            usort($stops, fn (array $first, array $second): int => ((int) ($first['ordre'] ?? 0)) <=> ((int) ($second['ordre'] ?? 0)));
            $previousTime = null;
            foreach ($stops as $stopIndex => $stop) {
                foreach (['employes' => 'demande_employe_id', 'objets' => 'demande_objet_id'] as $collection => $identifier) {
                    $ids = array_map(fn (array $item): int => (int) $item[$identifier], array_filter($stop[$collection] ?? [], 'is_array'));
                    if (count($ids) !== count(array_unique($ids))) {
                        $validator->errors()->add('plan.arrets.'.$stopIndex.'.'.$collection, 'Un élément ne peut apparaître qu’une fois dans un même arrêt.');
                    }
                }

                $stopTime = $stop['heure_prevue'] ?? null;
                if ($stopTime === null) {
                    continue;
                }
                $currentTime = new \DateTimeImmutable($stopTime);
                if ($previousTime !== null && $currentTime < $previousTime) {
                    $validator->errors()->add('plan.arrets.'.$stopIndex.'.heure_prevue', 'Les horaires des arrêts doivent respecter leur ordre.');
                }
                $previousTime = $currentTime;
            }
        });
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()], 422));
    }
}
