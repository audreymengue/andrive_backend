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
        Schema::table('vehicules', function (Blueprint $table): void {
            $table->string('marque', 255)->nullable();
            $table->string('modele', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('vehicules')->whereNotNull('marque')->orWhereNotNull('modele')->exists()) {
            throw new RuntimeException('Retour arrière refusé : les données renseignées doivent être préservées.');
        }

        Schema::table('vehicules', function (Blueprint $table): void {
            $table->dropColumn(['marque', 'modele']);
        });
    }
};
