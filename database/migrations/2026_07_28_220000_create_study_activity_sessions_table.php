<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_activity_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_session_id', 64);
            $table->string('category', 24);
            $table->string('activity', 32);
            $table->string('source', 24);
            $table->string('name', 120)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('audio_playback_ms')->nullable();
            $table->unsignedInteger('cards_created')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'client_session_id']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_activity_sessions');
    }
};
