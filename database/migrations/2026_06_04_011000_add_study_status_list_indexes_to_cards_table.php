<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DECK_STUDY_LIST_INDEX = 'cards_deck_study_deleted_created_id_idx';

    private const STUDY_LIST_INDEX = 'cards_study_deleted_created_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->index(
                ['deck_id', 'study_status', 'deleted_at', 'created_at', 'id'],
                self::DECK_STUDY_LIST_INDEX,
            );
            $table->index(
                ['study_status', 'deleted_at', 'created_at', 'id'],
                self::STUDY_LIST_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(self::DECK_STUDY_LIST_INDEX);
            $table->dropIndex(self::STUDY_LIST_INDEX);
        });
    }
};
