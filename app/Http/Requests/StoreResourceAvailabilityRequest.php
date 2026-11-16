<?php

namespace App\Http\Requests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class StoreResourceAvailabilityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'type_ressource' => ['required', 'in:VEHICULE,CHAUFFEUR'],
            'ressource_id' => ['required', 'integer', 'min:1'],
            'version_ressource_attendue' => ['required', 'integer', 'min:0'],
            'debut_a' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'fin_estimee_a' => ['present', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'motif' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['type_ressource', 'ressource_id', 'version_ressource_attendue', 'debut_a', 'fin_estimee_a', 'motif']) !== []) {
                $validator->errors()->add('body', 'Un ou plusieurs champs ne sont pas acceptés.');
            }
            $start = $this->input('debut_a');
            $estimate = $this->input('fin_estimee_a');
            if ($start !== null && $estimate !== null && strtotime($estimate) < strtotime($start)) {
                $validator->errors()->add('fin_estimee_a', 'La fin estimée doit être postérieure ou égale au début.');
            }
        });
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()], 422));
    }
}
