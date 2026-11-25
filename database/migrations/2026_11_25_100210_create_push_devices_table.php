<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->uuid('installation_id')->unique();
            $table->string('plateforme', 16);
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('push_devices')->exists()) {
            throw new RuntimeException('Retour arrière refusé : des installations sont inscrites aux notifications push.');
        }

        Schema::dropIfExists('push_devices');
    }
};
