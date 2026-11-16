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
        Schema::create('demande_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demandes')->cascadeOnDelete();
            $table->string('ancien_statut')->nullable();
            $table->string('nouveau_statut');
            $table->foreignId('utilisateur_id')->constrained('users')->restrictOnDelete();
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->index(['demande_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demande_historiques');
    }
};
