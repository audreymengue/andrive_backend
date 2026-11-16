<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

class OrganisationHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'actor_id' => $this->actor_id === null ? null : (int) $this->actor_id,
            'origine' => $this->origine,
            'motif' => $this->motif,
            'before_values' => $this->snapshot($this->before_values),
            'after_values' => $this->snapshot($this->after_values),
            'command_key' => $this->command_key,
            'created_at' => CarbonImmutable::parse($this->created_at, config('app.timezone'))->toISOString(),
        ];
    }

    private function snapshot(?string $json): ?stdClass
    {
        $value = $json === null ? null : json_decode($json, true);
        if (! is_array($value)) {
            return null;
        }
        $safe = [];
        foreach (['direction_id', 'service_id'] as $field) {
            if (array_key_exists($field, $value) && (is_int($value[$field]) || $value[$field] === null)) {
                $safe[$field] = $value[$field];
            }
        }
        foreach (['direction', 'service'] as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }
            if ($value[$field] === null) {
                $safe[$field] = null;
            } elseif (is_array($value[$field])) {
                $structure = [];
                foreach (['id', 'nom', 'code'] as $attribute) {
                    if (array_key_exists($attribute, $value[$field]) && ($attribute === 'id' ? is_int($value[$field][$attribute]) : is_string($value[$field][$attribute]))) {
                        $structure[$attribute] = $value[$field][$attribute];
                    }
                }
                $safe[$field] = (object) $structure;
            }
        }

        return (object) $safe;
    }
}
