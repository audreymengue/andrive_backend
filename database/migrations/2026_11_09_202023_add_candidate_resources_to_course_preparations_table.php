<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_preparations', function (Blueprint $table): void {
            $table->foreignId('chauffeur_id')->nullable()->after('actor_id')->constrained('chauffeurs')->restrictOnDelete();
            $table->foreignId('vehicule_id')->nullable()->after('chauffeur_id')->constrained('vehicules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('course_preparations')->whereNotNull('chauffeur_id')->orWhereNotNull('vehicule_id')->exists()) {
            throw new RuntimeException('Retour arrière refusé : les sélections candidates historiques doivent être préservées.');
        }

        Schema::table('course_preparations', function (Blueprint $table): void {
            $table->dropForeign(['chauffeur_id']);
            $table->dropForeign(['vehicule_id']);
            $table->dropColumn(['chauffeur_id', 'vehicule_id']);
        });
    }
};
