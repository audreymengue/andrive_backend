<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'email' => 'Le champ :attribute doit être une adresse email valide.',
            'min' => 'Le champ :attribute doit contenir au moins :min caractères.',
            'max' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
            'different' => 'Le champ :attribute doit être différent de :other.',
            'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
            'unique' => 'La valeur du champ :attribute est déjà utilisée.',
            'exists' => 'La valeur sélectionnée pour :attribute est invalide.',
            'array' => 'Le champ :attribute doit être un tableau.',
            'integer' => 'Le champ :attribute doit être un entier.',
            'string' => 'Le champ :attribute doit être une chaîne de caractères.',
            'in' => 'La valeur sélectionnée pour :attribute est invalide.',
            'distinct' => 'Le champ :attribute contient des valeurs dupliquées.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'mot de passe actuel',
            'new_password' => 'nouveau mot de passe',
            'new_password_confirmation' => 'confirmation du nouveau mot de passe',
            'direction_id' => 'direction',
            'role_ids' => 'rôles',
            'permission_ids' => 'permissions',
        ];
    }
}
