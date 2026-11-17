<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

#[FailOnUnknownFields]
class UpdateEntiteGeographiqueRequest extends ApiFormRequest
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
            'adresse' => ['sometimes', 'required', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric:strict', 'min:-90', 'max:90', 'decimal:0,7'],
            'longitude' => ['sometimes', 'nullable', 'numeric:strict', 'min:-180', 'max:180', 'decimal:0,7'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
