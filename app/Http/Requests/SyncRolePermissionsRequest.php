<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class SyncRolePermissionsRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permission_ids' => ['present', 'array', 'list'],
            'permission_ids.*' => ['integer', 'numeric:strict', 'min:1', 'distinct'],
        ];
    }
}
