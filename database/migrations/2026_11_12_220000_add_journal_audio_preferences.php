<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audio_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('voice_id', 100);
        });
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('audio_voice_id', 100)->nullable();
            $table->string('audio_text_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn(['audio_voice_id', 'audio_text_hash']);
        });
        Schema::dropIfExists('journal_audio_preferences');
    }
};
