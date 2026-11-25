<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PushDeviceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = ['installation_id' => ['required', 'uuid']];

        if ($this->isMethod('PUT')) {
            $rules['plateforme'] = ['required', Rule::in(['android', 'ios', 'web'])];
            $rules['token'] = ['bail', 'required', 'string', 'max:4096', 'regex:/^[!-~]+$/D'];
        }

        return $rules;
    }

    public function validationData(): array
    {
        $payload = json_decode($this->getContent(), true);

        return [
            ...(is_array($payload) ? $payload : []),
            'installation_id' => $this->route('installation_id'),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->query() !== []) {
                $validator->errors()->add('query', 'Cette opération n’accepte aucun paramètre de requête.');
            }

            if ($this->isMethod('PUT')) {
                if (! $this->isJson()) {
                    $validator->errors()->add('body', 'Un objet JSON est attendu.');
                }
                foreach (array_diff(array_keys($this->json()->all()), ['plateforme', 'token']) as $field) {
                    $validator->errors()->add($field, 'Ce champ ne peut pas être fourni.');
                }
            } elseif ($this->getContent() !== '' && json_decode($this->getContent(), true) !== []) {
                $validator->errors()->add('body', 'La révocation ne requiert aucun corps de requête.');
            }
        }];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Les données fournies sont invalides.',
            'code' => 'VALIDATION_ERROR',
            'errors' => $validator->errors(),
        ], 422));
    }
}
