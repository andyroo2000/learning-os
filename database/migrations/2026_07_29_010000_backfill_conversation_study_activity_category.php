<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('study_activity_sessions')
            ->where('activity', 'conversation')
            ->where('category', 'immerse')
            ->update(['category' => 'conversation']);
    }

    public function down(): void
    {
        DB::table('study_activity_sessions')
            ->where('activity', 'conversation')
            ->where('category', 'conversation')
            ->update(['category' => 'immerse']);
    }
};
