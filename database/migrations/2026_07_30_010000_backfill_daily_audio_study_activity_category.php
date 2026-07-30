<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('study_activity_sessions')
            ->where('activity', 'daily_audio')
            ->where('category', 'review')
            ->update(['category' => 'listen']);
    }

    public function down(): void
    {
        // Rolling back this migration also rolls application code back to an enum
        // without Listen. Restore the legacy category for every Daily Audio row,
        // including sessions created after this migration ran.
        DB::table('study_activity_sessions')
            ->where('activity', 'daily_audio')
            ->where('category', 'listen')
            ->update(['category' => 'review']);
    }
};
