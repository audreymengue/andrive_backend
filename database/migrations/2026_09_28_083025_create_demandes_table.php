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
        Schema::create('demandes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 13)->unique();
            $table->foreignId('demandeur_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->date('date_depart');
            $table->time('heure_depart');
            $table->time('heure_retour')->nullable();
            $table->text('motif');
            $table->text('commentaire')->nullable();
            $table->string('statut')->default('BROUILLON')->index();
            $table->timestamps();

            $table->index(['demandeur_id', 'created_at']);
            $table->index(['direction_id', 'date_depart']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demandes');
    }
};
