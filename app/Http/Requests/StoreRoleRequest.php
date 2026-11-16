<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(['ACTIF', 'INACTIF'])],
        ];
    }
}
