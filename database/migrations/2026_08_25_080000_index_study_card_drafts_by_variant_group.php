<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'study_card_drafts_user_variant_group_idx';

    public function up(): void
    {
        Schema::table('study_card_drafts', function (Blueprint $table): void {
            $table->index(['user_id', 'variant_group_id'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('study_card_drafts', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
