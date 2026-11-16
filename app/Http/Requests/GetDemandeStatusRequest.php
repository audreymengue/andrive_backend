<?php

namespace App\Http\Requests;

use App\Models\Demande;
use Illuminate\Contracts\Validation\ValidationRule;

class GetDemandeStatusRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'regex:'.Demande::REFERENCE_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'reference' => 'référence',
        ];
    }
}
