<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['types_sortie', 'entites_geographiques'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'usage_observe_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->timestamp('usage_observe_at')->nullable();
                    $blueprint->string('origine_usage', 20)->nullable();
                });
            }
        }

        DB::transaction(function (): void {
            DB::table('organisation_guards')->where('id', 1)->lockForUpdate()->first();
            $observation = ['usage_observe_at' => now(), 'origine_usage' => 'reprise'];
            foreach (['demandes', 'demande_modifications'] as $source) {
                foreach (['type_sortie_id' => 'types_sortie', 'adresse_depart_id' => 'entites_geographiques', 'adresse_retour_id' => 'entites_geographiques'] as $field => $catalog) {
                    DB::table($catalog)->whereNull('usage_observe_at')
                        ->whereIn('id', DB::table($source)->select($field))->update($observation);
                }
            }

            DB::table('entites_geographiques')->whereNull('usage_observe_at')
                ->whereIn('id', DB::table('demande_destinations')->select('entite_geographique_id'))->update($observation);

            DB::table('demande_modifications')->select(['id', 'destinations'])->chunkById(100, function (Collection $rows) use ($observation): void {
                $ids = [];
                foreach ($rows as $row) {
                    foreach (json_decode($row->destinations, true, flags: JSON_THROW_ON_ERROR) ?? [] as $destination) {
                        if (isset($destination['entite_geographique_id'])) {
                            $ids[] = (int) $destination['entite_geographique_id'];
                        }
                    }
                }
                DB::table('entites_geographiques')->whereNull('usage_observe_at')->whereIntegerInRaw('id', array_unique($ids))->update($observation);
            });

            DB::table('organisation_audits as audit')
                ->join('organisation_commands as cmd', function ($join): void {
                    $join->on('cmd.command_key', '=', 'audit.command_key')->on('cmd.actor_id', '=', 'audit.actor_id');
                })
                ->whereIn('audit.object_type', ['type_sortie', 'entite_geographique'])
                ->where('cmd.response->data->utilise', true)
                ->select(['audit.id', 'audit.object_type', 'audit.object_id', 'cmd.response'])
                ->chunkById(100, function (Collection $rows) use ($observation): void {
                    foreach ($rows as $row) {
                        $data = json_decode($row->response, true, flags: JSON_THROW_ON_ERROR)['data'];
                        if (($data['utilise'] ?? null) === true && ($data['id'] ?? null) === (int) $row->object_id) {
                            $table = $row->object_type === 'type_sortie' ? 'types_sortie' : 'entites_geographiques';
                            DB::table($table)->where('id', $row->object_id)->whereNull('usage_observe_at')->update($observation);
                        }
                    }
                }, 'audit.id', 'id');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNotNull('usage_observe_at')->exists()) {
                throw new RuntimeException('Retour arrière refusé : des utilisations historiques seraient perdues.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(['usage_observe_at', 'origine_usage']));
        }
    }
};
