<?php

namespace App\Http\Requests;

use App\Models\Direction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrganisationRequest extends ApiFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || $user->fresh()?->statut !== 'ACTIF' || $user->fresh()?->must_change_password) {
            return false;
        }
        if ($this->route('organisation_admin') && ! $user->roles()->where('system_key', 'super_admin')->where('statut', 'ACTIF')->exists()) {
            return false;
        }
        $permission = $this->route('organisation_permission');

        return $user->permissions()->where('name', $permission)->exists()
            || $user->roles()->where('statut', 'ACTIF')->whereHas('permissions', fn ($query) => $query->where('name', $permission))->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isMethod('get')) {
            if ($this->route('organisation_action') !== 'list') {
                return [];
            }
            $rules = [
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ];
            if ($this->route('organisation_admin')) {
                $rules['statut'] = ['sometimes', Rule::in(['ACTIF', 'INACTIF'])];
            }
            if ($this->route('organisation_kind') === 'service') {
                $rules['direction_id'] = [$this->route('organisation_admin') ? 'sometimes' : 'required', 'integer', 'min:1'];
            }

            return $rules;
        }
        $rules = ['idempotency_key' => ['required', 'uuid']];
        if ($this->isMethod('post')) {
            $rules += ['nom' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string']];
            if ($this->route('organisation_kind') === 'service') {
                $rules['direction_id'] = ['required', 'integer', 'numeric:strict', 'min:1'];
                $rules['description'][] = 'max:1000';
            }
        } else {
            $rules['version_attendue'] = ['required', 'integer', 'numeric:strict', 'min:0'];
            $rules['motif'] = ['required', 'string', 'min:1', 'max:1000'];
            if ($this->route('organisation_action') === 'update') {
                $rules += ['nom' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string']];
                if ($this->route('organisation_kind') === 'service') {
                    $rules['description'][] = 'max:1000';
                }
                $rules['motif'][0] = 'sometimes';
            }
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        foreach (['nom', 'code', 'motif'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $this->merge([$key => $key === 'code' ? mb_strtoupper($value) : $value]);
            }
        }
    }

    public function validationData(): array
    {
        return $this->isMethod('get') ? parent::validationData() : [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isMethod('get') && $this->route('organisation_action') !== 'list') {
                return;
            }
            $allowed = array_diff(array_keys($this->rules()), ['idempotency_key']);
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Ce champ ne peut pas être modifié par cette opération.');
            }
            if ($this->isMethod('get')) {
                if (! $this->route('organisation_admin') && $this->has('statut')) {
                    $validator->errors()->add('statut', 'Le catalogue opérationnel contient uniquement des ressources actives.');
                }
                if ($this->route('organisation_kind') !== 'service' && $this->has('direction_id')) {
                    $validator->errors()->add('direction_id', 'Ce filtre concerne uniquement les services.');
                }

                if (
                    $this->route('organisation_kind') === 'service'
                    && ! $this->route('organisation_admin')
                    && ! $validator->errors()->has('direction_id')
                    && ! Direction::query()->whereKey($this->integer('direction_id'))->where('statut', 'ACTIF')->exists()
                ) {
                    $validator->errors()->add('direction_id', 'La direction sélectionnée est inexistante ou inactive.');
                }
            }
        });
    }

    protected function failedAuthorization(): void
    {
        $code = $this->user()?->must_change_password ? 'PASSWORD_CHANGE_REQUIRED' : 'FORBIDDEN';
        throw new HttpResponseException(response()->json(['message' => $code === 'PASSWORD_CHANGE_REQUIRED' ? 'Vous devez modifier votre mot de passe avant de poursuivre.' : 'Vous n’avez pas la permission d’effectuer cette action.', 'code' => $code], 403));
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Les données fournies sont invalides.', 'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()], 422));
    }
}
