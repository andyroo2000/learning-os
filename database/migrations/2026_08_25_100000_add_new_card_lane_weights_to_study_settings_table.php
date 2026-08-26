<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('standard_lane_weight')->default(3)->after('review_time_budget_minutes');
            $table->unsignedTinyInteger('lesson_followup_lane_weight')->default(1)->after('standard_lane_weight');
            $table->unsignedTinyInteger('wanikani_lane_weight')->default(1)->after('lesson_followup_lane_weight');
        });
    }

    public function down(): void
    {
        Schema::table('study_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'standard_lane_weight',
                'lesson_followup_lane_weight',
                'wanikani_lane_weight',
            ]);
        });
    }
};
