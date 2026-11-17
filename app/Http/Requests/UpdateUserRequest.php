<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'prenom' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('id')),
            ],
            'service_id' => ['sometimes', 'nullable', 'integer', 'numeric:strict', 'min:1', Rule::exists('services', 'id')],
            'direction_id' => ['sometimes', 'nullable', 'integer', 'numeric:strict', 'min:1', Rule::exists('directions', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedFields = ['nom', 'prenom', 'email', 'direction_id', 'service_id', 'version_attendue', 'motif'];
            foreach (array_diff(array_keys($this->all()), $allowedFields) as $field) {
                $validator->errors()->add($field, 'Ce champ n’est pas pris en charge par cette opération.');
            }
        });
    }
}
