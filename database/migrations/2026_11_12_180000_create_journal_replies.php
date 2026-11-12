<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_replies', function (Blueprint $table): void {
            $table->ulid('id');
            $table->primary('id');
            $table->foreignUlid('analysis_id')->constrained('journal_analyses')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('journal_replies')->nullOnDelete();
            $table->unsignedInteger('base_revision');
            $table->boolean('replaces_answer')->default(false);
            $table->text('answer');
            $table->string('status', 20)->default('queued');
            $table->json('result')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->ulid('attempt_id')->nullable();
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['analysis_id', 'id']);
        });
        Schema::table('journal_analyses', function (Blueprint $table): void {
            // Pointers are checked under the owner/entry/analysis locks; no cyclic foreign keys.
            $table->ulid('current_reply_id')->nullable();
            $table->ulid('pending_reply_id')->nullable();
            $table->unsignedInteger('reply_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('journal_analyses', fn (Blueprint $table) => $table->dropColumn(['current_reply_id', 'pending_reply_id', 'reply_revision']));
        Schema::dropIfExists('journal_replies');
    }
};
