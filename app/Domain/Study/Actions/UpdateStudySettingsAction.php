<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Sync\StudySettingsSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class UpdateStudySettingsAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

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
            $operation = $settings->exists ? SyncFeedOperation::Update : SyncFeedOperation::Create;
            $wasUpdated = $settings->isDirty(['new_cards_per_day']);

            $settings->saveOrFail();

            if (! $wasUpdated) {
                return $settings;
            }

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: StudySettingsSyncPayload::DOMAIN,
                    resourceType: StudySettingsSyncPayload::RESOURCE_TYPE,
                    resourceId: StudySettingsSyncPayload::RESOURCE_ID,
                    operation: $operation->value,
                    payload: StudySettingsSyncPayload::fromSettings($settings),
                ),
            );

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
