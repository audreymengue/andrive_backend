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
        Schema::table('demande_destinations', function (Blueprint $table) {
            $table->foreignId('entite_geographique_id')
                ->nullable()
                ->after('demande_id')
                ->constrained('entites_geographiques')
                ->restrictOnDelete();
        });

        DB::table('demande_destinations')
            ->select(['id', 'libelle'])
            ->orderBy('id')
            ->each(function (object $destination): void {
                $adresse = trim((string) $destination->libelle);

                if ($adresse === '') {
                    throw new RuntimeException("La destination {$destination->id} ne contient pas de libellé migrable.");
                }

                $entiteId = DB::table('entites_geographiques')
                    ->where('adresse', $adresse)
                    ->value('id');

                if ($entiteId === null) {
                    $entiteId = DB::table('entites_geographiques')->insertGetId([
                        'adresse' => $adresse,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('demande_destinations')
                    ->where('id', $destination->id)
                    ->update(['entite_geographique_id' => $entiteId]);
            });

        Schema::table('demande_destinations', function (Blueprint $table) {
            $table->unsignedBigInteger('entite_geographique_id')->nullable(false)->change();
            $table->dropColumn('libelle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demande_destinations', function (Blueprint $table) {
            throw new RuntimeException('La restauration des libellés de destination supprimés nécessite une migration corrective explicite.');
        });
    }
};
