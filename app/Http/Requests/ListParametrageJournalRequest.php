<?php

namespace App\Http\Requests;

use App\Services\ParametrageSecurity;

class ListParametrageJournalRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(ParametrageSecurity::class)->authorize($this, $this->route('journal_permission'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->route('journal_action') === 'show') {
            return [];
        }
        $rules = [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
        if ($this->route('journal_action') === 'history') {
            return $rules;
        }
        $rules += [
            'date_debut' => ['nullable', 'date_format:Y-m-d'],
            'date_fin' => ['nullable', 'date_format:Y-m-d'],
            'action' => ['nullable', 'string', 'max:255'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'object_type' => ['nullable', 'string', 'max:255'],
            'object_id' => ['nullable', 'integer', 'min:1'],
        ];
        if ($this->filled('date_debut')) {
            $rules['date_fin'][] = 'after_or_equal:date_debut';
        }

        return $rules;
    }
}
