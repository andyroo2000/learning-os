<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wanikani_connections', function (Blueprint $table): void {
            $table->unsignedInteger('review_count')->nullable()->after('last_synced_at');
            $table->timestampTz('review_count_updated_at', 6)->nullable()->after('review_count');
        });
        Schema::table('google_calendar_event_mirrors', function (Blueprint $table): void {
            $table->index(
                ['google_calendar_connection_id', 'status', 'starts_at'],
                'google_calendar_event_mirrors_next_lesson_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_event_mirrors', function (Blueprint $table): void {
            $table->dropIndex('google_calendar_event_mirrors_next_lesson_index');
        });
        Schema::table('wanikani_connections', function (Blueprint $table): void {
            $table->dropColumn(['review_count', 'review_count_updated_at']);
        });
    }
};
