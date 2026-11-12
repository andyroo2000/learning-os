<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('title')->nullable();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            // Owner-scoped live archive ordered by local entry date, then stable ID.
            $table->index(['user_id', 'deleted_at', 'entry_date', 'id'], 'journal_entries_archive_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
