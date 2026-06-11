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
        ]);
        $settings->user_id = $userId;

        return $settings;
    }
}
