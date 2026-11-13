<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_questions', function (Blueprint $table): void {
            $table->string('audio_status', 20)->default('idle');
            $table->ulid('audio_attempt_id')->nullable();
            $table->timestamp('audio_requested_at', 6)->nullable();
            $table->string('audio_path', 500)->nullable();
            $table->string('audio_error', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('journal_questions', fn (Blueprint $table) => $table->dropColumn(['audio_status', 'audio_attempt_id', 'audio_requested_at', 'audio_path', 'audio_error']));
    }
};
