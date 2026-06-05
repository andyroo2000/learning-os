<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DECK_NOTE_LIST_INDEX = 'cards_deck_note_ord_created_id_idx';

    private const DECK_NOTETYPE_NOTE_LIST_INDEX = 'cards_deck_notetype_note_ord_created_id_idx';

    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->index(
                ['deck_id', 'deleted_at', 'source_note_id', 'source_template_ord', 'created_at', 'id'],
                self::DECK_NOTE_LIST_INDEX,
            );
            $table->index(
                ['deck_id', 'deleted_at', 'source_notetype_name', 'source_note_id', 'source_template_ord', 'created_at', 'id'],
                self::DECK_NOTETYPE_NOTE_LIST_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_NOTETYPE_NOTE_LIST_INDEX);
            $table->dropIndex(self::DECK_NOTE_LIST_INDEX);
        });
    }
};
