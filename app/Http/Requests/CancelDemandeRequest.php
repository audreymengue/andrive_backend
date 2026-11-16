<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class CancelDemandeRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'motif' => 'motif',
        ];
    }
}
