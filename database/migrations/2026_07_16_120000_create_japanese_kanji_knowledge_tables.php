<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('japanese_knowledge_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('knowledge_version')->default(0);
            $table->timestamps();
        });

        Schema::create('wanikani_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_token');
            $table->timestamp('assignments_synced_through_at', 6)->nullable();
            $table->timestamp('last_synced_at', 6)->nullable();
            $table->timestamps();
        });

        Schema::create('user_known_kanji', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('character', 4);
            $table->unsignedBigInteger('wanikani_subject_id')->nullable();
            $table->timestamp('wanikani_passed_at', 6)->nullable();
            $table->timestamp('manually_added_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'character'], 'known_kanji_user_character_unique');
            $table->unique(['user_id', 'wanikani_subject_id'], 'known_kanji_user_wanikani_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_known_kanji');
        Schema::dropIfExists('wanikani_connections');
        Schema::dropIfExists('japanese_knowledge_profiles');
    }
};
