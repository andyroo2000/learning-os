<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->softDeletes();
            $table->dropIndex('decks_user_created_id_index');
            $table->index(['user_id', 'deleted_at', 'created_at', 'id'], 'decks_user_deleted_created_id_index');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->softDeletes();
            $table->dropIndex('cards_deck_created_id_index');
            $table->index(['deck_id', 'deleted_at', 'created_at', 'id'], 'cards_deck_deleted_created_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex('cards_deck_deleted_created_id_index');
            $table->dropSoftDeletes();
            $table->index(['deck_id', 'created_at', 'id'], 'cards_deck_created_id_index');
        });

        Schema::table('decks', function (Blueprint $table) {
            $table->dropIndex('decks_user_deleted_created_id_index');
            $table->dropSoftDeletes();
            $table->index(['user_id', 'created_at', 'id'], 'decks_user_created_id_index');
        });
    }
};
