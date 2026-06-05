<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DECK_TYPE_LIST_INDEX = 'cards_deck_type_deleted_created_id_idx';

    private const TYPE_LIST_INDEX = 'cards_type_deleted_created_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->index(
                ['deck_id', 'card_type', 'deleted_at', 'created_at', 'id'],
                self::DECK_TYPE_LIST_INDEX,
            );
            $table->index(
                ['card_type', 'deleted_at', 'created_at', 'id'],
                self::TYPE_LIST_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_TYPE_LIST_INDEX);
            $table->dropIndex(self::TYPE_LIST_INDEX);
        });
    }
};
