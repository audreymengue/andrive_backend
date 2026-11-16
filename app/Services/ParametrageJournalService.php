<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

class ParametrageJournalService
{
    /** @param array<string, mixed> $filters */
    public function history(string $userId, array $filters): LengthAwarePaginator
    {
        User::query()->select('id')->findOrFail($userId);

        return DB::table('user_organisation_histories')
            ->select(['id', 'user_id', 'actor_id', 'origine', 'motif', 'before_values', 'after_values', 'command_key', 'created_at'])
            ->where('user_id', $userId)->orderBy('created_at')->orderBy('id')
            ->paginate($filters['per_page'] ?? 20, page: $filters['page'] ?? 1);
    }

    /** @param array<string, mixed> $filters */
    public function audit(array $filters): LengthAwarePaginator
    {
        $query = $this->auditQuery();
        foreach (['action', 'actor_id', 'object_type', 'object_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['date_debut'])) {
            $query->where('created_at', '>=', $filters['date_debut'].' 00:00:00');
        }
        if (isset($filters['date_fin'])) {
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_fin'], config('app.timezone'))->addDay();
            $query->where('created_at', '<', $end->format('Y-m-d H:i:s'));
        }

        return $query->orderByDesc('created_at')->orderBy('source')->orderByDesc('source_id')
            ->paginate($filters['per_page'] ?? 20, page: $filters['page'] ?? 1);
    }

    public function findAudit(string $id): stdClass
    {
        [$source, $sourceId] = explode(':', $id, 2);
        $event = $this->auditQuery()->where('source', $source)->where('source_id', $sourceId)->first();
        abort_if($event === null, 404);

        return $event;
    }

    private function auditQuery(): Builder
    {
        $administration = DB::table('organisation_audits')
            ->selectRaw("'administration' AS source, id AS source_id, actor_id, NULL AS origine, action, object_type, object_id, motif, before_values, after_values, command_key, created_at");
        $maintenance = DB::table('parametrage_maintenance_audits')
            ->selectRaw("'maintenance' AS source, id AS source_id, NULL AS actor_id, origine, NULL AS action, object_type, object_id, NULL AS motif, before_values, after_values, command_key, created_at");

        return DB::query()->fromSub($administration->unionAll($maintenance), 'journal');
    }
}
