<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_clarifications', function (Blueprint $table): void {
            $table->json('prepared_feedback')->nullable();
            $table->string('prepared_context_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('journal_clarifications', function (Blueprint $table): void {
            $table->dropColumn(['prepared_feedback', 'prepared_context_hash']);
        });
    }
};
