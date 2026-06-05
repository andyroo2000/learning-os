<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DECK_NEW_QUEUE_INDEX = 'cards_deck_deleted_study_new_pos_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->index(
                ['deck_id', 'deleted_at', 'study_status', 'new_queue_position', 'id'],
                self::DECK_NEW_QUEUE_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_NEW_QUEUE_INDEX);
        });
    }
};
