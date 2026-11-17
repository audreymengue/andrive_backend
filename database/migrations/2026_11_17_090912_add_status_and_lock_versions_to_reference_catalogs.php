<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types_sortie', function (Blueprint $table): void {
            $table->string('statut', 16)->default('ACTIF')->index();
            $table->unsignedInteger('lock_version')->default(0);
        });

        Schema::table('entites_geographiques', function (Blueprint $table): void {
            $table->string('statut', 16)->default('ACTIF')->index();
            $table->unsignedInteger('lock_version')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['types_sortie', 'entites_geographiques'] as $table) {
            if (DB::table($table)->where('statut', '<>', 'ACTIF')->orWhere('lock_version', '<>', 0)->exists()) {
                throw new RuntimeException('Retour arrière refusé : des statuts ou versions de références ont été modifiés.');
            }
        }

        Schema::table('types_sortie', function (Blueprint $table): void {
            $table->dropIndex(['statut']);
            $table->dropColumn(['statut', 'lock_version']);
        });

        Schema::table('entites_geographiques', function (Blueprint $table): void {
            $table->dropIndex(['statut']);
            $table->dropColumn(['statut', 'lock_version']);
        });
    }
};
