<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Sync\StudySettingsSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudySettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/study/settings')->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $this->patchJson('/api/study/settings', [
            'new_cards_per_day' => 12,
        ])->assertUnauthorized();
    }

    public function test_show_returns_existing_settings(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 32,
        ]);

        $response = $this->getJson('/api/study/settings');

        $response
            ->assertOk()
            ->assertJsonPath('data.new_cards_per_day', 32)
            ->assertJsonStructure([
                'data' => [
                    'new_cards_per_day',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_show_returns_only_the_authenticated_users_settings(): void
    {
        StudySettings::factory()->create([
            'new_cards_per_day' => 32,
        ]);
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 12,
        ]);

        $this->getJson('/api/study/settings')
            ->assertOk()
            ->assertJsonPath('data.new_cards_per_day', 12);
    }

    public function test_show_creates_default_settings_when_missing(): void
    {
        $user = $this->signIn();

        $response = $this->getJson('/api/study/settings');

        $response
            ->assertOk()
            ->assertJsonPath('data.new_cards_per_day', StudySettings::DEFAULT_NEW_CARDS_PER_DAY);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        ]);
    }

    public function test_update_changes_settings(): void
    {
        $user = $this->signIn();

        $response = $this->patchJson('/api/study/settings', [
            'new_cards_per_day' => '12',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.new_cards_per_day', 12);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);

        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $user->id,
            'domain' => StudySettingsSyncPayload::DOMAIN,
            'resource_type' => StudySettingsSyncPayload::RESOURCE_TYPE,
            'resource_id' => StudySettingsSyncPayload::RESOURCE_ID,
            'operation' => SyncFeedOperation::Create->value,
        ]);
    }

    public function test_update_writes_a_replayable_sync_feed_payload(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);

        $this->patchJson('/api/study/settings', [
            'new_cards_per_day' => 12,
        ])->assertOk();

        $response = $this->getJson('/api/sync/feed?domain=study&resource_type=settings&resource_id=settings');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain', StudySettingsSyncPayload::DOMAIN)
            ->assertJsonPath('data.0.resource_type', StudySettingsSyncPayload::RESOURCE_TYPE)
            ->assertJsonPath('data.0.resource_id', StudySettingsSyncPayload::RESOURCE_ID)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Update->value)
            ->assertJsonPath('data.0.payload.id', StudySettingsSyncPayload::RESOURCE_ID)
            ->assertJsonPath('data.0.payload.new_cards_per_day', 12);
    }

    public function test_update_does_not_change_another_users_settings(): void
    {
        $otherSettings = StudySettings::factory()->create([
            'new_cards_per_day' => 32,
        ]);
        $user = $this->signIn();

        $this->patchJson('/api/study/settings', [
            'new_cards_per_day' => 12,
        ])->assertOk();

        $this->assertSame(32, $otherSettings->refresh()->new_cards_per_day);
        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);
    }

    public function test_update_rejects_missing_malformed_and_out_of_range_values(): void
    {
        $this->signIn();

        $this->patchJson('/api/study/settings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_cards_per_day']);

        $this->patchJson('/api/study/settings', ['new_cards_per_day' => 'twelve'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_cards_per_day']);

        $this->patchJson('/api/study/settings', ['new_cards_per_day' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_cards_per_day']);

        $this->patchJson('/api/study/settings', ['new_cards_per_day' => 1001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_cards_per_day']);

        $this->patchJson('/api/study/settings', ['new_cards_per_day' => ['12']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_cards_per_day']);
    }
}
