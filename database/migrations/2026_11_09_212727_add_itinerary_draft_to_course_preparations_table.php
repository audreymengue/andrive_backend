<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_preparations', function (Blueprint $table): void {
            $table->json('plan')->nullable()->after('vehicule_id');
        });
    }

    public function down(): void
    {
        if (DB::table('course_preparations')->whereNotNull('plan')->exists()) {
            throw new RuntimeException('Retour arrière refusé : les plans préparatoires historiques doivent être préservés.');
        }

        Schema::table('course_preparations', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
    }
};
