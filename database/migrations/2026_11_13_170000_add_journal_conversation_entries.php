<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignUlid('root_entry_id')->nullable()->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUlid('question_id')->nullable()->constrained('journal_questions')->nullOnDelete();
            $table->unsignedInteger('conversation_position')->nullable();
            $table->unique('question_id', 'journal_entries_question_unique');
            $table->index(['root_entry_id', 'conversation_position'], 'journal_conversation_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_conversation_order_idx');
            $table->dropUnique('journal_entries_question_unique');
            $table->dropForeign(['question_id']);
            $table->dropForeign(['root_entry_id']);
            $table->dropColumn(['question_id', 'root_entry_id', 'conversation_position']);
        });
    }
};
