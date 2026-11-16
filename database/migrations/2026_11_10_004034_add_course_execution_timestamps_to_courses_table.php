<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->timestamp('demarree_a')->nullable();
            $table->timestamp('terminee_a')->nullable();
            $table->timestamp('chauffeur_libere_a')->nullable();
            $table->timestamp('vehicule_libere_a')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['demarree_a', 'terminee_a', 'chauffeur_libere_a', 'vehicule_libere_a']);
        });
    }
};
