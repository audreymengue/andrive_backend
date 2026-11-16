<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('demandes', function (Blueprint $table) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demandes', function (Blueprint $table) {
            $table->dropForeign(['type_sortie_id']);
            $table->dropForeign(['adresse_depart_id']);
            $table->dropForeign(['adresse_retour_id']);
            $table->dropColumn(['type_sortie_id', 'adresse_depart_id', 'adresse_retour_id']);
        });
    }
};
