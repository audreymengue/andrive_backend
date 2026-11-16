<?php

namespace App\Http\Requests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class StoreTransportSignalementRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', 'in:RETARD,PANNE,ABSENCE,IMPOSSIBILITE,INDISPONIBILITE'],
            'type_ressource' => ['required', 'in:VEHICULE,CHAUFFEUR'],
            'ressource_id' => ['required', 'integer', 'min:1'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'details' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['type', 'type_ressource', 'ressource_id', 'course_id', 'details']) !== []) {
                $validator->errors()->add('body', 'Un ou plusieurs champs ne sont pas acceptés.');
            }
            if ($this->input('type_ressource') === 'VEHICULE' && ! $this->filled('course_id')) {
                $validator->errors()->add('course_id', 'Une Course actuellement affectée est obligatoire pour signaler un véhicule.');
            }
        });
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()], 422));
    }
}
