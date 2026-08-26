<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudySettings;

class GetStudySettingsAction
{
    public function handle(int $userId): StudySettings
    {
        $settings = StudySettings::query()
            ->where('user_id', $userId)
            ->first();

        if ($settings !== null) {
            return $settings;
        }

        // Missing rows are effective defaults for reads; UpdateStudySettingsAction owns materializing writes.
        $settings = new StudySettings([
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
            'lesson_batch_size' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
            'review_time_budget_minutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
            'standard_lane_weight' => StudySettings::DEFAULT_STANDARD_LANE_WEIGHT,
            'lesson_followup_lane_weight' => StudySettings::DEFAULT_LESSON_FOLLOWUP_LANE_WEIGHT,
            'wanikani_lane_weight' => StudySettings::DEFAULT_WANIKANI_LANE_WEIGHT,
        ]);
        $settings->user_id = $userId;

        return $settings;
    }
}
