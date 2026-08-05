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

    public function handle(
        int $userId,
        ?int $newCardsPerDay,
        ?int $lessonBatchSize = null,
        ?int $reviewTimeBudgetMinutes = null,
    ): StudySettings {
        if ($newCardsPerDay === null && $lessonBatchSize === null && $reviewTimeBudgetMinutes === null) {
            throw new InvalidArgumentException('At least one study setting must be provided.');
        }

        if ($newCardsPerDay !== null && ($newCardsPerDay < 0 || $newCardsPerDay > StudySettings::MAX_NEW_CARDS_PER_DAY)) {
            throw new InvalidArgumentException(
                'new_cards_per_day must be an integer between 0 and '.StudySettings::MAX_NEW_CARDS_PER_DAY.'.',
            );
        }

        if (
            $lessonBatchSize !== null
            && ($lessonBatchSize < StudySettings::MIN_LESSON_BATCH_SIZE
                || $lessonBatchSize > StudySettings::MAX_LESSON_BATCH_SIZE)
        ) {
            throw new InvalidArgumentException(
                'lesson_batch_size must be an integer between '
                .StudySettings::MIN_LESSON_BATCH_SIZE.' and '.StudySettings::MAX_LESSON_BATCH_SIZE.'.',
            );
        }

        if (
            $reviewTimeBudgetMinutes !== null
            && ($reviewTimeBudgetMinutes < StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES
                || $reviewTimeBudgetMinutes > StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES)
        ) {
            throw new InvalidArgumentException(
                'review_time_budget_minutes must be an integer between '
                .StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES.' and '
                .StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES.'.',
            );
        }

        return DB::transaction(function () use (
            $userId,
            $newCardsPerDay,
            $lessonBatchSize,
            $reviewTimeBudgetMinutes,
        ): StudySettings {
            $this->lockSettingsOwner($userId);

            $settings = StudySettings::query()
                ->where('user_id', $userId)
                ->first();

            if ($settings === null) {
                $settings = new StudySettings([
                    'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
                    'lesson_batch_size' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                    'review_time_budget_minutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
                ]);
                $settings->user_id = $userId;
            }

            if ($newCardsPerDay !== null) {
                $settings->new_cards_per_day = $newCardsPerDay;
            }
            if ($lessonBatchSize !== null) {
                $settings->lesson_batch_size = $lessonBatchSize;
            }
            if ($reviewTimeBudgetMinutes !== null) {
                $settings->review_time_budget_minutes = $reviewTimeBudgetMinutes;
            }
            $operation = $settings->exists ? SyncFeedOperation::Update : SyncFeedOperation::Create;
            $wasUpdated = $settings->isDirty([
                'new_cards_per_day',
                'lesson_batch_size',
                'review_time_budget_minutes',
            ]);

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
