<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_REVIEW_TIME_BUDGET_MINUTES = 90;

    public function up(): void
    {
        Schema::table('study_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('review_time_budget_minutes')
                ->default(self::DEFAULT_REVIEW_TIME_BUDGET_MINUTES)
                ->after('lesson_batch_size');
        });
    }

    public function down(): void
    {
        Schema::table('study_settings', function (Blueprint $table) {
            $table->dropColumn('review_time_budget_minutes');
        });
    }
};
