<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_books', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id']);
        });
        Schema::create('reading_book_entries', function (Blueprint $table): void {
            $table->foreignUlid('reading_id')->primary()->constrained('readings')->cascadeOnDelete();
            $table->foreignUlid('book_id')->constrained('reading_books')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unique(['book_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_book_entries');
        Schema::dropIfExists('reading_books');
    }
};
