<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table): void {
            $table->string('reference_source')->nullable()->unique();
            $table->string('nom_complet')->nullable();
            $table->string('mention_pool')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('chauffeurs')->whereNotNull('reference_source')->orWhereNotNull('nom_complet')->orWhereNotNull('mention_pool')->exists()) {
            throw new RuntimeException('Retour arrière refusé : des fiches chauffeur utilisent les informations du pool.');
        }

        Schema::table('chauffeurs', function (Blueprint $table): void {
            $table->dropUnique(['reference_source']);
            $table->dropColumn(['reference_source', 'nom_complet', 'mention_pool']);
        });
    }
};
