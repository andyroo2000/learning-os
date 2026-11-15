<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_clarifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->unsignedInteger('source_version');
            $table->text('source_body');
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('revision')->default(0);
            $table->json('question')->nullable();
            $table->json('answers');
            $table->text('confirmed_meaning')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->ulid('attempt_id')->nullable();
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['entry_id', 'source_version'], 'journal_clarifications_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_clarifications');
    }
};
