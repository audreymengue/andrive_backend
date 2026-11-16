<?php

namespace App\Http\Requests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class FinishCourseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'version_attendue' => ['required', 'integer', 'min:0'],
            'fin_reelle' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'chauffeur_libere_a' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'vehicule_libere_a' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }

    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['version_attendue', 'fin_reelle', 'chauffeur_libere_a', 'vehicule_libere_a']) !== []) {
                $validator->errors()->add('body', 'Un ou plusieurs champs ne sont pas acceptés.');
            }
        });
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()], 422));
    }
}
