<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signalement_transports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reported_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('chauffeur_id')->constrained('chauffeurs')->restrictOnDelete();
            $table->foreignId('vehicule_id')->nullable()->constrained('vehicules')->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->string('type');
            $table->text('details');
            $table->timestamp('signale_a');
            $table->index(['course_id', 'signale_a']);
            $table->index(['chauffeur_id', 'signale_a']);
            $table->index(['vehicule_id', 'signale_a']);
        });
    }

    public function down(): void
    {
        if (DB::table('signalement_transports')->exists()) {
            throw new RuntimeException('Retour arrière refusé : les signalements transport doivent être préservés.');
        }
        Schema::dropIfExists('signalement_transports');
    }
};
