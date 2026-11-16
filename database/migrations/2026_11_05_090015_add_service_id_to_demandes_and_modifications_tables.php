<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('direction_id')->constrained('services')->restrictOnDelete();
        });

        Schema::table('demande_modifications', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('direction_id')->constrained('services')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('demande_modifications', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });

        Schema::table('demandes', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
