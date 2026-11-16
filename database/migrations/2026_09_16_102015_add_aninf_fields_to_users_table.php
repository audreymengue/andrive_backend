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
        Schema::table('users', function (Blueprint $table) {
            $table->string('nom')->nullable();
            $table->string('prenom')->nullable();
            $table->string('statut')->default('ACTIF')->index();
            $table->unsignedBigInteger('direction_id')->nullable()->index();
            $table->boolean('must_change_password')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropIndex(['direction_id']);
            $table->dropColumn([
                'nom',
                'prenom',
                'statut',
                'direction_id',
                'must_change_password',
            ]);
        });
    }
};
