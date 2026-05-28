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
        Schema::table('card_review_events', function (Blueprint $table) {
            $table->index(['card_id', 'reviewed_at', 'id'], 'card_review_events_card_reviewed_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_review_events', function (Blueprint $table) {
            $table->dropIndex('card_review_events_card_reviewed_id_index');
        });
    }
};
