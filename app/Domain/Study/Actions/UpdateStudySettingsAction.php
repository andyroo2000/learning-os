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
    /** @var list<string> */
    private const UPDATE_FIELDS = [
        'new_cards_per_day',
        'lesson_batch_size',
        'review_time_budget_minutes',
        'standard_lane_weight',
        'lesson_followup_lane_weight',
        'wanikani_lane_weight',
    ];

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(
        int $userId,
        ?int $newCardsPerDay,
        ?int $lessonBatchSize = null,
        ?int $reviewTimeBudgetMinutes = null,
        ?int $standardLaneWeight = null,
        ?int $lessonFollowupLaneWeight = null,
        ?int $wanikaniLaneWeight = null,
    ): StudySettings {
        $updates = [
            'new_cards_per_day' => $newCardsPerDay,
            'lesson_batch_size' => $lessonBatchSize,
            'review_time_budget_minutes' => $reviewTimeBudgetMinutes,
            'standard_lane_weight' => $standardLaneWeight,
            'lesson_followup_lane_weight' => $lessonFollowupLaneWeight,
            'wanikani_lane_weight' => $wanikaniLaneWeight,
        ];

        self::assertValidUpdates($updates);

        return $this->persistUpdates($userId, $updates);
    }

    /**
     * @param  array<string, int|null>  $updates
     */
    private static function assertValidUpdates(array $updates): void
    {
        if (! self::hasUpdates($updates)) {
            throw new InvalidArgumentException('At least one study setting must be provided.');
        }

        self::assertInRange([
            'field' => 'standard_lane_weight',
            'value' => $updates['standard_lane_weight'],
            'minimum' => StudySettings::MIN_STANDARD_LANE_WEIGHT,
            'maximum' => StudySettings::MAX_LANE_WEIGHT,
        ]);
        self::assertInRange([
            'field' => 'lesson_followup_lane_weight',
            'value' => $updates['lesson_followup_lane_weight'],
            'minimum' => StudySettings::MIN_PRIORITY_LANE_WEIGHT,
            'maximum' => StudySettings::MAX_LANE_WEIGHT,
        ]);
        self::assertInRange([
            'field' => 'wanikani_lane_weight',
            'value' => $updates['wanikani_lane_weight'],
            'minimum' => StudySettings::MIN_PRIORITY_LANE_WEIGHT,
            'maximum' => StudySettings::MAX_LANE_WEIGHT,
        ]);
        self::assertInRange([
            'field' => 'new_cards_per_day',
            'value' => $updates['new_cards_per_day'],
            'minimum' => StudySettings::MIN_NEW_CARDS_PER_DAY,
            'maximum' => StudySettings::MAX_NEW_CARDS_PER_DAY,
        ]);
        self::assertInRange([
            'field' => 'lesson_batch_size',
            'value' => $updates['lesson_batch_size'],
            'minimum' => StudySettings::MIN_LESSON_BATCH_SIZE,
            'maximum' => StudySettings::MAX_LESSON_BATCH_SIZE,
        ]);
        self::assertInRange([
            'field' => 'review_time_budget_minutes',
            'value' => $updates['review_time_budget_minutes'],
            'minimum' => StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES,
            'maximum' => StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
        ]);
    }

    /**
     * @param  array<string, int|null>  $updates
     */
    private static function hasUpdates(array $updates): bool
    {
        foreach ($updates as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{field: string, value: int|null, minimum: int, maximum: int}  $range
     */
    private static function assertInRange(array $range): void
    {
        if ($range['value'] === null) {
            return;
        }

        if ($range['value'] < $range['minimum']) {
            throw self::rangeException($range);
        }

        if ($range['value'] > $range['maximum']) {
            throw self::rangeException($range);
        }
    }

    /**
     * @param  array{field: string, value: int|null, minimum: int, maximum: int}  $range
     */
    private static function rangeException(array $range): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "{$range['field']} must be an integer between {$range['minimum']} and {$range['maximum']}.",
        );
    }

    /**
     * @param  array<string, int|null>  $updates
     */
    private function persistUpdates(int $userId, array $updates): StudySettings
    {
        return DB::transaction(function () use ($userId, $updates): StudySettings {
            $this->lockSettingsOwner($userId);

            $settings = $this->settingsForUpdate($userId);
            self::applyUpdates($settings, $updates);
            $operation = $settings->exists ? SyncFeedOperation::Update : SyncFeedOperation::Create;
            $wasUpdated = $settings->isDirty(self::UPDATE_FIELDS);

            $settings->saveOrFail();

            if ($wasUpdated) {
                $this->recordSync($userId, $settings, $operation);
            }

            return $settings;
        });
    }

    private function settingsForUpdate(int $userId): StudySettings
    {
        $settings = StudySettings::query()
            ->where('user_id', $userId)
            ->first();

        if ($settings instanceof StudySettings) {
            return $settings;
        }

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

    /**
     * @param  array<string, int|null>  $updates
     */
    private static function applyUpdates(StudySettings $settings, array $updates): void
    {
        foreach ($updates as $field => $value) {
            if ($value !== null) {
                $settings->{$field} = $value;
            }
        }
    }

    private function recordSync(
        int $userId,
        StudySettings $settings,
        SyncFeedOperation $operation,
    ): void {
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
