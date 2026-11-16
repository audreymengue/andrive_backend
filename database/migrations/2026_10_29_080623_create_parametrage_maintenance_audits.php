<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametrage_maintenance_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('origine');
            $table->string('object_type');
            $table->unsignedBigInteger('object_id');
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->uuid('command_key');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Conserver les traces de maintenance ; pas de suppression automatique.');
    }
};
