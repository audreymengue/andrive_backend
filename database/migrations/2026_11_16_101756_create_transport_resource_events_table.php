<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_resource_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('indisponibilite_ressource_id')->constrained('indisponibilite_ressources')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->unsignedInteger('version');
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->text('motif_evenement')->nullable();
            $table->uuid('command_key');
            $table->timestamp('created_at');
            $table->index(['indisponibilite_ressource_id', 'version'], 'transport_event_period_version_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('transport_resource_events')->exists()) {
            throw new RuntimeException('Retour arrière refusé : l’historique Transport doit être préservé.');
        }
        Schema::dropIfExists('transport_resource_events');
    }
};
