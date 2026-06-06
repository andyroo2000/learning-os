<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MANUAL_LOOKUP_INDEX = 'decks_manual_lookup_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->boolean('is_manual_study_deck')
                ->default(false)
                ->after('description');
            $table->index(['user_id', 'course_id', 'is_manual_study_deck', 'deleted_at'], self::MANUAL_LOOKUP_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->dropIndex(self::MANUAL_LOOKUP_INDEX);
            $table->dropColumn('is_manual_study_deck');
        });
    }
};
