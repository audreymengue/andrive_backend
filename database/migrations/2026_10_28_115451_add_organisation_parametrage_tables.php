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
        Schema::table('directions', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(0);
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('system_key')->nullable()->unique();
        });
        DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->update(['system_key' => 'super_admin']);
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->string('nom');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('statut')->default('ACTIF')->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['direction_id', 'code']);
        });
        Schema::create('organisation_guards', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('organisation_guards')->insert(['id' => 1]);
        Schema::create('organisation_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('command_key');
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('http_status');
            $table->json('response');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'command_key']);
        });
        Schema::create('organisation_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('object_type');
            $table->unsignedBigInteger('object_id');
            $table->text('motif')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->uuid('command_key');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['services', 'organisation_audits', 'organisation_commands'] as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Retour arrière refusé : des données organisationnelles doivent être préservées.');
            }
        }
        Schema::dropIfExists('organisation_audits');
        Schema::dropIfExists('organisation_commands');
        Schema::dropIfExists('organisation_guards');
        Schema::dropIfExists('services');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('system_key'));
        Schema::table('directions', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
