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
        Schema::create('demande_modifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->unique()->constrained('demandes')->cascadeOnDelete();
            $table->foreignId('demandeur_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->date('date_depart');
            $table->time('heure_depart');
            $table->time('heure_retour')->nullable();
            $table->text('motif');
            $table->text('commentaire')->nullable();
            $table->json('destinations');
            $table->json('employes');
            $table->json('objets')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demande_modifications');
    }
};
