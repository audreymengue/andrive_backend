<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

#[FailOnUnknownFields]
class UpdateTypeSortieRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version_attendue' => ['required', 'integer', 'numeric:strict', 'min:0'],
            'motif' => ['sometimes', 'string', 'min:1', 'max:1000'],
            'libelle' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
