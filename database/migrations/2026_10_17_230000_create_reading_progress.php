<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table): void {
            $table->ulid('reading_id')->primary();
            $table->foreign('reading_id')->references('id')->on('readings')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('sentence_index')->nullable();
            $table->unsignedInteger('reading_revision');
            $table->uuid('event_id');
            $table->timestampTz('read_at', 3);
            $table->index(['read_at', 'reading_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};
