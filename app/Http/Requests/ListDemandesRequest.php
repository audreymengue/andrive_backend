<?php

namespace App\Http\Requests;

use App\Models\Demande;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListDemandesRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:13'],
            'direction_libelle' => ['nullable', 'string', 'max:255'],
            'demandeur_nom' => ['nullable', 'string', 'max:255'],
            'statut' => ['nullable', Rule::in(Demande::STATUTS)],
            'direction_id' => ['nullable', 'integer', 'min:1'],
            'type_sortie_id' => ['nullable', 'integer', 'min:1'],
            'demandeur_id' => ['nullable', 'integer', 'min:1'],
            'date_depart_from' => ['nullable', 'date_format:Y-m-d'],
            'date_depart_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['date_depart_from', 'date_depart_to'])) {
                    return;
                }

                $dateFrom = $this->input('date_depart_from');
                $dateTo = $this->input('date_depart_to');

                if (is_string($dateFrom) && is_string($dateTo) && $dateTo < $dateFrom) {
                    $validator->errors()->add(
                        'date_depart_to',
                        'La date de départ maximale doit être postérieure ou égale à la date minimale.',
                    );
                }
            },
        ];
    }
}
