<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'study_activity_user_started_id_index';

    private const PREVIOUS_INDEX = 'study_activity_sessions_user_id_started_at_index';

    public function up(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->dropIndex(self::PREVIOUS_INDEX);
            // user_id scopes every page; started_at and id match the descending
            // keyset order, including the deterministic same-timestamp tie-breaker.
            // This supersedes the shorter prefix index instead of duplicating it.
            $table->index(['user_id', 'started_at', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->index(['user_id', 'started_at'], self::PREVIOUS_INDEX);
        });
    }
};
