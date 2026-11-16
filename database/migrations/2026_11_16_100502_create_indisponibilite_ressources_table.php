<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indisponibilite_ressources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicule_id')->nullable()->constrained('vehicules')->restrictOnDelete();
            $table->foreignId('chauffeur_id')->nullable()->constrained('chauffeurs')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('debut_a');
            $table->dateTime('fin_estimee_a')->nullable();
            $table->dateTime('fin_effective_a')->nullable();
            $table->text('motif');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['vehicule_id', 'fin_effective_a', 'debut_a'], 'indispo_vehicule_open_idx');
            $table->index(['chauffeur_id', 'fin_effective_a', 'debut_a'], 'indispo_chauffeur_open_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('indisponibilite_ressources')->exists()) {
            throw new RuntimeException('Retour arrière refusé : les périodes d’indisponibilité doivent être préservées.');
        }
        Schema::dropIfExists('indisponibilite_ressources');
    }
};
