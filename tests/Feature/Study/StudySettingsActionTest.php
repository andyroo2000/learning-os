<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\GetStudySettingsAction;
use App\Domain\Study\Actions\UpdateStudySettingsAction;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Sync\StudySettingsSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class StudySettingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_creates_default_settings_once(): void
    {
        $user = User::factory()->create();

        $first = app(GetStudySettingsAction::class)->handle($user->id);
        $second = app(GetStudySettingsAction::class)->handle($user->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(StudySettings::DEFAULT_NEW_CARDS_PER_DAY, $first->new_cards_per_day);
        $this->assertDatabaseCount('study_settings', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_update_creates_settings_when_missing(): void
    {
        $user = User::factory()->create();

        $settings = app(UpdateStudySettingsAction::class)->handle($user->id, 12);

        $this->assertSame(12, $settings->new_cards_per_day);
        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(StudySettingsSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(StudySettingsSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame(StudySettingsSyncPayload::RESOURCE_ID, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame(StudySettingsSyncPayload::fromSettings($settings), $entry->payload);
    }

    public function test_update_creates_default_value_settings_and_records_sync_feed_entry(): void
    {
        $user = User::factory()->create();

        $settings = app(UpdateStudySettingsAction::class)->handle(
            $user->id,
            StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        );

        $this->assertSame(StudySettings::DEFAULT_NEW_CARDS_PER_DAY, $settings->new_cards_per_day);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame(StudySettingsSyncPayload::fromSettings($settings), $entry->payload);
    }

    public function test_update_changes_existing_settings_and_records_sync_feed_entry(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);

        $updated = app(UpdateStudySettingsAction::class)->handle($settings->user_id, 0);

        $this->assertSame(0, $updated->new_cards_per_day);
        $this->assertDatabaseCount('study_settings', 1);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($settings->user_id, $entry->user_id);
        $this->assertSame(StudySettingsSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(StudySettingsSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame(StudySettingsSyncPayload::RESOURCE_ID, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame(StudySettingsSyncPayload::fromSettings($updated), $entry->payload);
    }

    public function test_update_accepts_supported_range_boundaries(): void
    {
        $lowerSettings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);
        $upperSettings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);

        $lowerUpdated = app(UpdateStudySettingsAction::class)->handle($lowerSettings->user_id, 0);
        $upperUpdated = app(UpdateStudySettingsAction::class)->handle(
            $upperSettings->user_id,
            StudySettings::MAX_NEW_CARDS_PER_DAY,
        );

        $this->assertSame(0, $lowerUpdated->new_cards_per_day);
        $this->assertSame(StudySettings::MAX_NEW_CARDS_PER_DAY, $upperUpdated->new_cards_per_day);
        $this->assertDatabaseHas('study_settings', [
            'user_id' => $lowerSettings->user_id,
            'new_cards_per_day' => 0,
        ]);
        $this->assertDatabaseHas('study_settings', [
            'user_id' => $upperSettings->user_id,
            'new_cards_per_day' => StudySettings::MAX_NEW_CARDS_PER_DAY,
        ]);
        $this->assertSame(2, SyncFeedEntry::query()->count());
    }

    public function test_update_does_not_record_sync_feed_entry_when_settings_are_unchanged(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);

        $updated = app(UpdateStudySettingsAction::class)->handle($settings->user_id, 20);

        $this->assertSame(20, $updated->new_cards_per_day);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_update_rolls_back_settings_when_recording_sync_feed_fails(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);
        $updateStudySettings = new UpdateStudySettingsAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Simulated sync feed failure.');
                }
            },
        );

        try {
            $updateStudySettings->handle($settings->user_id, 12);
            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated sync feed failure.', $exception->getMessage());
            $this->assertSame(20, $settings->refresh()->new_cards_per_day);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_update_only_changes_the_requested_users_settings(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);
        $otherSettings = StudySettings::factory()->create([
            'new_cards_per_day' => 42,
        ]);

        app(UpdateStudySettingsAction::class)->handle($settings->user_id, 12);

        $this->assertSame(12, $settings->refresh()->new_cards_per_day);
        $this->assertSame(42, $otherSettings->refresh()->new_cards_per_day);
    }

    public function test_update_rejects_values_outside_the_supported_range(): void
    {
        $expectedMessage = 'new_cards_per_day must be an integer between 0 and '
            .StudySettings::MAX_NEW_CARDS_PER_DAY.'.';

        foreach ([-1, StudySettings::MAX_NEW_CARDS_PER_DAY + 1] as $newCardsPerDay) {
            try {
                app(UpdateStudySettingsAction::class)->handle(PHP_INT_MAX, $newCardsPerDay);
                $this->fail("Expected [{$newCardsPerDay}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('study_settings', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_update_rejects_missing_settings_owner_without_creating_orphan_settings(): void
    {
        try {
            app(UpdateStudySettingsAction::class)->handle(999, 12);
            $this->fail('Expected missing settings owner to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Study settings owner could not be locked.', $exception->getMessage());
        }

        $this->assertDatabaseCount('study_settings', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
