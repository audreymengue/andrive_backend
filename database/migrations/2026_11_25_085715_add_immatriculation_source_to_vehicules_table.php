<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table): void {
            $table->string('immatriculation_source')->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (DB::table('vehicules')->whereNotNull('immatriculation_source')->exists()) {
            throw new RuntimeException('Retour arrière refusé : des véhicules sont identifiés dans le parc officiel.');
        }

        Schema::table('vehicules', function (Blueprint $table): void {
            $table->dropUnique(['immatriculation_source']);
            $table->dropColumn('immatriculation_source');
        });
    }
};
