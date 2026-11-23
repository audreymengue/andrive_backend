<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\ParametrageSecuritySeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

class ParametrageAuditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->source.':'.$this->source_id,
            'source' => $this->source,
            'source_id' => (int) $this->source_id,
            'actor_id' => $this->actor_id === null ? null : (int) $this->actor_id,
            'origine' => $this->origine,
            'action' => $this->action,
            'object_type' => $this->object_type,
            'object_id' => (int) $this->object_id,
            'resultat' => $this->result(),
            'motif' => $this->motif,
            'before_values' => $this->snapshot($this->before_values),
            'after_values' => $this->snapshot($this->after_values),
            'command_key' => $this->command_key,
            'created_at' => CarbonImmutable::parse($this->created_at, config('app.timezone'))->toISOString(),
            'schema_version' => 1,
        ];
    }

    private function result(): ?string
    {
        if ($this->source === 'maintenance') {
            return in_array($this->origine, [ParametrageSecuritySeeder::class, AdminUserSeeder::class, TestDataSeeder::class], true) ? 'SUCCES' : null;
        }
        if ($this->action === 'refus') {
            return 'REFUSE';
        }

        $knownWrites = ['create_user', 'update_user', 'activate_user', 'deactivate_user', 'reset_user_password', 'assign_role', 'create_role', 'update_role', 'delete_role', 'assign_permission', 'create_permission', 'update_permission', 'delete_permission', 'create', 'update', 'activate', 'deactivate', 'reveal'];

        return in_array($this->action, $knownWrites, true) ? 'SUCCES' : null;
    }

    private function snapshot(?string $json): ?stdClass
    {
        $snapshot = $json === null ? null : json_decode($json, true);
        if (! is_array($snapshot)) {
            return null;
        }
        $types = match ($this->object_type) {
            'user' => ['id' => 'integer', 'nom' => 'string', 'prenom' => 'string', 'email' => 'string', 'statut' => 'string', 'direction_id' => 'integer', 'service_id' => 'integer', 'must_change_password' => 'boolean', 'password_generation' => 'integer', 'lock_version' => 'integer', 'role_ids' => 'integers'],
            'role' => ['id' => 'integer', 'name' => 'string', 'description' => 'string', 'statut' => 'string', 'system_key' => 'string', 'lock_version' => 'integer', 'permission_ids' => 'integers', 'permissions' => 'strings'],
            'permission' => ['id' => 'integer', 'name' => 'string', 'libelle' => 'string', 'description' => 'string', 'lock_version' => 'integer'],
            'direction' => ['id' => 'integer', 'nom' => 'string', 'code' => 'string', 'description' => 'string', 'statut' => 'string', 'lock_version' => 'integer'],
            'service' => ['id' => 'integer', 'direction_id' => 'integer', 'nom' => 'string', 'code' => 'string', 'description' => 'string', 'statut' => 'string', 'lock_version' => 'integer'],
            'vehicule' => ['id' => 'integer', 'immatriculation' => 'string', 'marque' => 'string', 'modele' => 'string', 'places_utiles' => 'integer', 'statut' => 'string', 'lock_version' => 'integer'],
            'chauffeur' => ['id' => 'integer', 'user_id' => 'integer', 'statut' => 'string', 'lock_version' => 'integer'],
            default => [],
        };
        if ($this->action === 'refus') {
            $types = ['operation' => 'string'];
        }
        $safe = [];
        foreach ($types as $field => $type) {
            if (! array_key_exists($field, $snapshot)) {
                continue;
            }
            $value = $snapshot[$field];
            $valid = $value === null || match ($type) {
                'integer' => is_int($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'integers', 'strings' => is_array($value) && array_is_list($value) && count(array_filter($value, $type === 'integers' ? is_int(...) : is_string(...))) === count($value),
            };
            if ($valid) {
                $safe[$field] = $value;
            }
        }

        return (object) $safe;
    }
}
