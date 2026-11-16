<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_guards', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('course_guards')->insert(['id' => 1]);

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('statut_execution')->nullable()->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        Schema::create('course_preparations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'version']);
        });

        Schema::create('course_preparation_demandes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_preparation_id')->constrained('course_preparations')->restrictOnDelete();
            $table->foreignId('demande_id')->constrained('demandes')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_preparation_id', 'demande_id'], 'course_prep_demande_unique');
        });

        Schema::create('course_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->unsignedInteger('version');
            $table->json('demande_ids');
            $table->uuid('command_key');
            $table->timestamp('created_at');
        });

        Schema::create('course_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('command_key');
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('http_status');
            $table->json('response');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'command_key']);
        });
    }

    public function down(): void
    {
        foreach (['course_events', 'course_commands', 'course_preparation_demandes', 'course_preparations', 'courses'] as $table) {
            if (DB::table($table)->exists()) {
                $exceptionClass = 'RuntimeException';
                throw new $exceptionClass('Retour arrière refusé : les préparations et traces de Course doivent être préservées.');
            }
        }

        Schema::dropIfExists('course_commands');
        Schema::dropIfExists('course_events');
        Schema::dropIfExists('course_preparation_demandes');
        Schema::dropIfExists('course_preparations');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_guards');
    }
};
