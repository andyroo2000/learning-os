<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cards', 'study_card_drafts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignUlid('book_pile_id')->nullable()->constrained('study_piles')->restrictOnDelete();
                $table->foreignUlid('book_chapter_id')->nullable()->constrained('study_pile_chapters')->restrictOnDelete();
                $table->unsignedInteger('book_position')->nullable();
                $table->string('book_reference', 512)->nullable();
                $table->index(['book_pile_id', 'book_chapter_id', 'book_position']);
            });
        }
    }

    public function down(): void
    {
        foreach (['cards', 'study_card_drafts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['book_pile_id', 'book_chapter_id', 'book_position']);
                $table->dropConstrainedForeignId('book_chapter_id');
                $table->dropConstrainedForeignId('book_pile_id');
                $table->dropColumn(['book_position', 'book_reference']);
            });
        }
    }
};
