<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

#[FailOnUnknownFields]
class StoreEntiteGeographiqueRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adresse' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric:strict', 'min:-90', 'max:90', 'decimal:0,7'],
            'longitude' => ['nullable', 'numeric:strict', 'min:-180', 'max:180', 'decimal:0,7'],
            'description' => ['nullable', 'string'],
        ];
    }
}
