<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_questions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('analysis_id')->constrained('journal_analyses')->cascadeOnDelete();
            // "entry" identifies the initial question; reply ULIDs identify subsequent questions.
            $table->string('source_key', 26);
            $table->text('text');
            $table->text('reading');
            $table->text('translation');
            $table->json('tutor')->nullable();
            $table->timestamps(6);
            $table->unique(['analysis_id', 'source_key'], 'journal_questions_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_questions');
    }
};
