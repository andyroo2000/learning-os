<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DECK_INTRODUCED_INDEX = 'cards_deck_deleted_introduced_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            // Study overview narrows decks by owner, then counts active cards introduced within a daily range.
            $table->index(
                ['deck_id', 'deleted_at', 'introduced_at', 'id'],
                self::DECK_INTRODUCED_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_INTRODUCED_INDEX);
        });
    }
};
