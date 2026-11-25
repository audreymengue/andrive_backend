<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rattachements_direction', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->string('code');
            $table->string('nom');
            $table->timestamps();
            $table->unique(['direction_id', 'code']);
        });

        foreach (['demandes', 'demande_modifications'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('rattachement_direction_id')->nullable()
                    ->constrained('rattachements_direction')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['demandes', 'demande_modifications'] as $name) {
            if (DB::table($name)->whereNotNull('rattachement_direction_id')->exists()) {
                throw new RuntimeException('Retour arrière refusé : un rattachement est utilisé par une demande ou une modification.');
            }
        }

        foreach (['demande_modifications', 'demandes'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('rattachement_direction_id');
            });
        }

        Schema::dropIfExists('rattachements_direction');
    }
};
