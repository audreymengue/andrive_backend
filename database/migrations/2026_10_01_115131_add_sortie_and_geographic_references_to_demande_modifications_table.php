<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('demande_modifications', function (Blueprint $table) {
            $table->foreignId('type_sortie_id')
                ->nullable()
                ->after('direction_id')
                ->constrained('types_sortie')
                ->restrictOnDelete();
            $table->foreignId('adresse_depart_id')
                ->nullable()
                ->after('type_sortie_id')
                ->constrained('entites_geographiques')
                ->restrictOnDelete();
            $table->foreignId('adresse_retour_id')
                ->nullable()
                ->after('adresse_depart_id')
                ->constrained('entites_geographiques')
                ->restrictOnDelete();
        });

        DB::table('demande_modifications')->orderBy('id')->each(function (object $modification): void {
            $destinations = json_decode($modification->destinations, true, 512, JSON_THROW_ON_ERROR);

            foreach ($destinations as &$destination) {
                if (isset($destination['entite_geographique_id'])) {
                    continue;
                }

                $adresse = trim((string) ($destination['libelle'] ?? ''));

                if ($adresse === '') {
                    throw new RuntimeException('Une destination de modification ne contient pas de libellé migrable.');
                }

                $id = DB::table('entites_geographiques')->where('adresse', $adresse)->value('id');
                $id ??= DB::table('entites_geographiques')->insertGetId([
                    'adresse' => $adresse,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $destination = ['entite_geographique_id' => $id, 'ordre' => $destination['ordre']];
            }
            unset($destination);

            DB::table('demande_modifications')->where('id', $modification->id)->update([
                'destinations' => json_encode($destinations, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demande_modifications', function (Blueprint $table) {
            $table->dropForeign(['type_sortie_id']);
            $table->dropForeign(['adresse_depart_id']);
            $table->dropForeign(['adresse_retour_id']);
            $table->dropColumn(['type_sortie_id', 'adresse_depart_id', 'adresse_retour_id']);
        });
    }
};
