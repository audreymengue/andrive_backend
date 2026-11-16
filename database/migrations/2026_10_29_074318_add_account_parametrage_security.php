<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedInteger('password_generation')->default(1);
        });
        foreach (['roles', 'permissions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedInteger('lock_version')->default(0);
            });
        }
        Schema::create('user_organisation_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('origine');
            $table->text('motif')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->uuid('command_key')->nullable();
            $table->timestamp('created_at');
        });
        DB::table('roles')->where('name', 'demandeur')->where('guard_name', 'web')->whereNull('system_key')->update(['system_key' => 'demandeur']);
        foreach (DB::table('users')->orderBy('id')->get(['id', 'direction_id']) as $user) {
            $direction = DB::table('directions')->find($user->direction_id);
            DB::table('user_organisation_histories')->insert([
                'user_id' => $user->id, 'origine' => 'observation_initiale', 'before_values' => null,
                'after_values' => json_encode(['direction_id' => $user->direction_id, 'service_id' => null, 'direction' => $direction === null ? null : ['id' => $direction->id, 'nom' => $direction->nom, 'code' => $direction->code], 'service' => null], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retour arrière automatique interdit : préserver historiques et versions.');
    }
};
