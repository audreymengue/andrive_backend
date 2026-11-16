<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class SyncUserRolesRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array', 'list'],
            'role_ids.*' => ['integer', 'numeric:strict', 'min:1', 'distinct'],
        ];
    }
}
