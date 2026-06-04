<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudySettings;
use Illuminate\Support\Facades\DB;
use LogicException;

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

        return DB::transaction(function () use ($userId): StudySettings {
            $this->lockSettingsOwner($userId);

            $settings = StudySettings::query()
                ->where('user_id', $userId)
                ->first();

            if ($settings !== null) {
                return $settings;
            }

            $settings = new StudySettings([
                'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
            ]);
            $settings->user_id = $userId;
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
