<?php

namespace App\Http\Requests;

use App\Services\PasswordService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class ChangePasswordRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currentPasswordRules = ['nullable', 'string'];
        $newPasswordRules = [
            'required',
            'string',
            'between:'.PasswordService::MIN_LENGTH.','.PasswordService::MAX_LENGTH,
            'confirmed',
        ];

        if (! $this->user()?->must_change_password) {
            $currentPasswordRules[] = 'required';
            $newPasswordRules[] = 'different:current_password';
        }

        return [
            'current_password' => $currentPasswordRules,
            'new_password' => $newPasswordRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'new_password.between' => 'Votre mot de passe doit comporter entre 8 et 12 caractères.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (
                    ! $user?->must_change_password
                    || $validator->errors()->has('new_password')
                    || ! Hash::check((string) $this->input('new_password'), $user->password)
                ) {
                    return;
                }

                $validator->errors()->add(
                    'new_password',
                    'Le nouveau mot de passe doit être différent du mot de passe temporaire.',
                );
            },
        ];
    }
}
