<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResourceProfileRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user()?->fresh();
        $permission = $this->route('resource_profile_permission');

        return $actor !== null
            && $actor->statut === 'ACTIF'
            && ! $actor->must_change_password
            && is_string($permission)
            && $actor->hasPermissionTo($permission);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $action = $this->route('resource_profile_action');
        $kind = $this->route('resource_profile_kind');

        if ($this->isMethod('get')) {
            if ($action !== 'list') {
                return [];
            }

            return [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'statut' => ['sometimes', Rule::in(['ACTIF', 'INACTIF'])],
            ];
        }

        $rules = ['idempotency_key' => ['required', 'uuid']];

        if (in_array($action, ['create', 'update'], true)) {
            $rules += $kind === 'vehicule' ? [
                'marque' => ['sometimes', 'nullable', 'string', 'max:255'],
                'modele' => ['sometimes', 'nullable', 'string', 'max:255'],
            ] : [
                'nom_complet' => ['sometimes', 'nullable', 'string', 'max:255'],
                'mention_pool' => ['sometimes', 'nullable', 'string', 'max:255'],
                'telephone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?(?=[0-9 ().-]*[0-9])[0-9 ().-]+$/D'],
            ];
        }

        if ($action === 'create' && $kind === 'vehicule') {
            return $rules + [
                'immatriculation' => ['required', 'string', 'min:1', 'max:255'],
                'places_utiles' => ['required', 'integer', 'numeric:strict', 'min:1'],
            ];
        }

        if ($action === 'create') {
            return $rules + ['user_id' => ['sometimes', 'nullable', 'integer', 'numeric:strict', 'min:1']];
        }

        if ($action === 'update' && $kind === 'vehicule') {
            return $rules + [
                'version_attendue' => ['required', 'integer', 'numeric:strict', 'min:0'],
                'immatriculation' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
                'places_utiles' => ['sometimes', 'required', 'integer', 'numeric:strict', 'min:1'],
                'motif' => ['sometimes', 'nullable', 'string', 'min:1', 'max:1000'],
            ];
        }

        if ($action === 'update') {
            return $rules + [
                'version_attendue' => ['required', 'integer', 'numeric:strict', 'min:0'],
                'user_id' => ['sometimes', 'nullable', 'integer', 'numeric:strict', 'min:1'],
                'motif' => ['sometimes', 'nullable', 'string', 'min:1', 'max:1000'],
            ];
        }

        return $rules + [
            'version_attendue' => ['required', 'integer', 'numeric:strict', 'min:0'],
            'motif' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('immatriculation'))) {
            $this->merge(['immatriculation' => mb_strtoupper(trim($this->input('immatriculation')))]);
        }
    }

    public function validationData(): array
    {
        return $this->isMethod('get')
            ? parent::validationData()
            : [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_diff(array_keys($this->rules()), ['idempotency_key']);
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Ce champ ne peut pas être modifié par cette opération.');
            }
        });
    }

    protected function failedAuthorization(): void
    {
        $passwordRequired = $this->user()?->must_change_password ?? false;
        throw new HttpResponseException(response()->json([
            'message' => $passwordRequired ? 'Vous devez modifier votre mot de passe avant de poursuivre.' : 'Vous n’avez pas la permission d’effectuer cette action.',
            'code' => $passwordRequired ? 'PASSWORD_CHANGE_REQUIRED' : 'FORBIDDEN',
        ], 403));
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Les données fournies sont invalides.',
            'code' => 'VALIDATION_ERROR',
            'errors' => $validator->errors(),
        ], 422));
    }
}
