<?php

namespace App\Http\Requests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class IndexResourceAvailabilityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'type_ressource' => ['sometimes', 'in:VEHICULE,CHAUFFEUR'],
            'ressource_id' => ['sometimes', 'integer', 'min:1'],
            'ouverte' => ['sometimes', 'in:true,false,0,1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Ce filtre n’est pas pris en charge.');
            }
        });
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()], 422));
    }
}
