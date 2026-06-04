<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudySettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class UpdateStudySettingsAction
{
    public function handle(int $userId, int $newCardsPerDay): StudySettings
    {
        if ($newCardsPerDay < 0 || $newCardsPerDay > StudySettings::MAX_NEW_CARDS_PER_DAY) {
            throw new InvalidArgumentException(
                'new_cards_per_day must be an integer between 0 and '.StudySettings::MAX_NEW_CARDS_PER_DAY.'.',
            );
        }

        return DB::transaction(function () use ($userId, $newCardsPerDay): StudySettings {
            $this->lockSettingsOwner($userId);

            $settings = StudySettings::query()
                ->where('user_id', $userId)
                ->first();

            if ($settings === null) {
                $settings = new StudySettings;
                $settings->user_id = $userId;
            }

            $settings->new_cards_per_day = $newCardsPerDay;
            $settings->saveOrFail();

            return $settings;
        });
    }

    private function lockSettingsOwner(int $userId): void
    {
        $lockedUserId = DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->value('id');

        if ($lockedUserId === null) {
            throw new LogicException('Study settings owner could not be locked.');
        }
    }
}
