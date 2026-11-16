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
        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('statut')->default('ACTIF')->index();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->string('libelle');
            $table->text('description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['libelle', 'description']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropColumn(['description', 'statut']);
        });
    }
};
