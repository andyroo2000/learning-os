<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STUDY_DUE_INDEX = 'cards_deck_study_due_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('study_status')
                ->default('new')
                ->after('back_text');
            $table->timestamp('due_at')->nullable()->after('study_status');
            $table->timestamp('introduced_at')->nullable()->after('due_at');
            $table->timestamp('failed_at')->nullable()->after('introduced_at');
            $table->timestamp('last_reviewed_at')->nullable()->after('failed_at');

            $table->index(['deck_id', 'study_status', 'due_at', 'id'], self::STUDY_DUE_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(self::STUDY_DUE_INDEX);
            $table->dropColumn([
                'study_status',
                'due_at',
                'introduced_at',
                'failed_at',
                'last_reviewed_at',
            ]);
        });
    }
};
