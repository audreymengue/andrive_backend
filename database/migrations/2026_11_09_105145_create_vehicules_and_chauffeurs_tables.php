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
        Schema::create('vehicules', function (Blueprint $table): void {
            $table->id();
            $table->string('immatriculation')->unique();
            $table->unsignedInteger('places_utiles');
            $table->string('statut')->default('ACTIF')->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        Schema::create('chauffeurs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('statut')->default('ACTIF')->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['chauffeurs', 'vehicules'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retour arrière refusé : les profils de ressources doivent être préservés.');
            }
        }

        Schema::dropIfExists('chauffeurs');
        Schema::dropIfExists('vehicules');
    }
};
