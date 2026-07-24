<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Domain\Study\Sync\StudySettingsSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
            ->assertExactJson([
                'newCardsPerDay' => 32,
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
            ->assertExactJson([
                'newCardsPerDay' => 12,
            ]);
    }

    public function test_show_returns_default_settings_without_materializing_them_when_missing(): void
    {
        $user = $this->signIn();

        $response = $this->getJson('/api/study/settings');

        $response
            ->assertOk()
            ->assertExactJson([
                'newCardsPerDay' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
            ]);

        $this->assertDatabaseMissing('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        ]);
    }

    public function test_show_missing_settings_uses_a_single_settings_lookup(): void
    {
        $this->signIn();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->getJson('/api/study/settings')
                ->assertOk()
                ->assertJsonPath('newCardsPerDay', StudySettings::DEFAULT_NEW_CARDS_PER_DAY);

            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $settingsQueries = $queries->filter(fn (array $query): bool => str_contains($query['query'], 'study_settings'));

        $this->assertCount(1, $settingsQueries, $queries->pluck('query')->implode("\n"));
        $this->assertDatabaseCount('study_settings', 0);
    }

    public function test_update_accepts_the_browser_contract_and_changes_settings(): void
    {
        $user = $this->signIn();

        $response = $this->patchJson('/api/study/settings', [
            'newCardsPerDay' => '+12',
        ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'newCardsPerDay' => 12,
            ]);

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

    public function test_update_continues_to_accept_the_canonical_field_name(): void
    {
        $user = $this->signIn();

        $this->patchJson('/api/study/settings', [
            'new_cards_per_day' => 12,
        ])
            ->assertOk()
            ->assertExactJson([
                'newCardsPerDay' => 12,
            ]);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);
    }

    public function test_update_accepts_matching_field_aliases(): void
    {
        $user = $this->signIn();

        $this->patchJson('/api/study/settings', [
            'newCardsPerDay' => '12',
            'new_cards_per_day' => 12,
        ])
            ->assertOk()
            ->assertExactJson([
                'newCardsPerDay' => 12,
            ]);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);
    }

    public function test_update_rejects_conflicting_field_aliases_without_writing(): void
    {
        $user = $this->signIn();
        $settings = StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);

        $this->patchJson('/api/study/settings', [
            'newCardsPerDay' => 12,
            'new_cards_per_day' => 13,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardsPerDay']);

        $this->assertSame(20, $settings->refresh()->new_cards_per_day);
        $this->assertDatabaseCount('sync_feed_entries', 0);
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

    public function test_update_is_rate_limited_by_user(): void
    {
        $limiter = new StudySettingsUpdateRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $settings = StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $otherUser = User::factory()->create();
        $otherSettings = StudySettings::factory()->for($otherUser)->create([
            'new_cards_per_day' => 40,
        ]);

        $restoreStudySettingsUpdateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudySettingsUpdateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so these match the request-derived keys used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(StudySettingsUpdateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            foreach ([11, 12] as $newCardsPerDay) {
                $this
                    ->patchJson('/api/study/settings', [
                        'new_cards_per_day' => $newCardsPerDay,
                    ])
                    ->assertOk();
            }

            $this->signIn($otherUser);

            $this
                ->patchJson('/api/study/settings', [
                    'new_cards_per_day' => 31,
                ])
                ->assertOk();

            $this->signIn($user);

            $this
                ->patchJson('/api/study/settings', [
                    'new_cards_per_day' => 13,
                ])
                ->assertTooManyRequests();

            $this->getJson('/api/study/settings')
                ->assertOk()
                ->assertJsonPath('newCardsPerDay', 12);

            $this->assertSame(12, $settings->refresh()->new_cards_per_day);
            $this->assertSame(31, $otherSettings->refresh()->new_cards_per_day);
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, SyncFeedEntry::query()->where('user_id', $otherUser->id)->count());
            $this->assertDatabaseMissing('sync_feed_entries', [
                'user_id' => $user->id,
                'payload->new_cards_per_day' => 13,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudySettingsUpdateLimiter();
        }
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

        $this->patchJson('/api/study/settings', ['newCardsPerDay' => 'twelve'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardsPerDay']);

        $this->patchJson('/api/study/settings', ['newCardsPerDay' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardsPerDay']);

        $this->patchJson('/api/study/settings', ['newCardsPerDay' => 1001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardsPerDay']);

        $this->patchJson('/api/study/settings', ['newCardsPerDay' => ['12']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardsPerDay']);
    }
}
