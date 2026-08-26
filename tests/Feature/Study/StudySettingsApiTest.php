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
                'lessonBatchSize' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 32,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
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
                'lessonBatchSize' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 12,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
            ]);
    }

    public function test_show_returns_default_settings_without_materializing_them_when_missing(): void
    {
        $user = $this->signIn();

        $response = $this->getJson('/api/study/settings');

        $response
            ->assertOk()
            ->assertExactJson([
                'lessonBatchSize' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
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
                'lessonBatchSize' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 12,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
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
                'lessonBatchSize' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 12,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
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
                'lessonBatchSize' => StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 12,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
            ]);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);
    }

    public function test_update_changes_lesson_batch_size_without_overwriting_daily_allowance(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 12,
            'lesson_batch_size' => 5,
        ]);

        $this->patchJson('/api/study/settings', [
            'lessonBatchSize' => 8,
        ])
            ->assertOk()
            ->assertExactJson([
                'lessonBatchSize' => 8,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 12,
                'reviewTimeBudgetMinutes' => StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
            ]);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
            'lesson_batch_size' => 8,
        ]);
    }

    public function test_update_changes_review_time_budget_without_overwriting_other_settings(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 12,
            'lesson_batch_size' => 5,
            'review_time_budget_minutes' => 60,
        ]);

        $this->patchJson('/api/study/settings', [
            'reviewTimeBudgetMinutes' => 90,
        ])
            ->assertOk()
            ->assertExactJson([
                'lessonBatchSize' => 5,
                'newCardLaneWeights' => $this->defaultLaneWeights(),
                'newCardsPerDay' => 12,
                'reviewTimeBudgetMinutes' => 90,
            ]);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
            'lesson_batch_size' => 5,
            'review_time_budget_minutes' => 90,
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $user->id,
            'payload->review_time_budget_minutes' => 90,
        ]);
    }

    public function test_update_accepts_review_time_budget_boundaries_and_aliases(): void
    {
        $user = $this->signIn();

        $this->patchJson('/api/study/settings', [
            'review_time_budget_minutes' => StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES,
        ])
            ->assertOk()
            ->assertJsonPath(
                'reviewTimeBudgetMinutes',
                StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES,
            );

        $this->patchJson('/api/study/settings', [
            'reviewTimeBudgetMinutes' => StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
            'review_time_budget_minutes' => StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
        ])
            ->assertOk()
            ->assertJsonPath(
                'reviewTimeBudgetMinutes',
                StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
            );

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'review_time_budget_minutes' => StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
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
            ->assertJsonValidationErrors(['settings']);

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

        foreach ([2, 11, 'five', ['5']] as $invalidBatchSize) {
            $this->patchJson('/api/study/settings', ['lesson_batch_size' => $invalidBatchSize])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['lesson_batch_size']);
        }

        foreach ([14, 241, 'ninety', ['90']] as $invalidBudget) {
            $this->patchJson('/api/study/settings', ['review_time_budget_minutes' => $invalidBudget])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['review_time_budget_minutes']);
        }

        $this->patchJson('/api/study/settings', [
            'reviewTimeBudgetMinutes' => 90,
            'review_time_budget_minutes' => 60,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reviewTimeBudgetMinutes']);
    }

    public function test_update_accepts_browser_lane_weights_and_returns_the_api_owned_mix(): void
    {
        $user = $this->signIn();

        $this->patchJson('/api/study/settings', [
            'newCardLaneWeights' => [
                'standard' => 5,
                'lessonFollowup' => 0,
                'wanikani' => 2,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('newCardLaneWeights.standard', 5)
            ->assertJsonPath('newCardLaneWeights.lessonFollowup', 0)
            ->assertJsonPath('newCardLaneWeights.wanikani', 2);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'standard_lane_weight' => 5,
            'lesson_followup_lane_weight' => 0,
            'wanikani_lane_weight' => 2,
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $user->id,
            'payload->new_card_lane_weights->standard' => 5,
            'payload->new_card_lane_weights->lesson_followup' => 0,
            'payload->new_card_lane_weights->wanikani' => 2,
        ]);
    }

    public function test_update_accepts_matching_canonical_lane_weight_alias(): void
    {
        $this->signIn();

        $this->patchJson('/api/study/settings', [
            'newCardLaneWeights' => [
                'standard' => '4',
                'lessonFollowup' => '2',
                'wanikani' => '1',
            ],
            'new_card_lane_weights' => [
                'standard' => 4,
                'lesson_followup' => 2,
                'wanikani' => 1,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('newCardLaneWeights.standard', 4)
            ->assertJsonPath('newCardLaneWeights.lessonFollowup', 2)
            ->assertJsonPath('newCardLaneWeights.wanikani', 1);
    }

    public function test_update_rejects_malformed_incomplete_and_conflicting_lane_weights(): void
    {
        $this->signIn();

        $this->patchJson('/api/study/settings', ['newCardLaneWeights' => '3:1:1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardLaneWeights']);

        $this->patchJson('/api/study/settings', [
            'newCardLaneWeights' => ['standard' => 3, 'lessonFollowup' => 1],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardLaneWeights.wanikani']);

        $this->patchJson('/api/study/settings', [
            'newCardLaneWeights' => [
                'standard' => 0,
                'lessonFollowup' => 21,
                'wanikani' => ['1'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'newCardLaneWeights.standard',
                'newCardLaneWeights.lessonFollowup',
                'newCardLaneWeights.wanikani',
            ]);

        $this->patchJson('/api/study/settings', [
            'newCardLaneWeights' => [
                'standard' => 3,
                'lessonFollowup' => 1,
                'wanikani' => 1,
            ],
            'new_card_lane_weights' => [
                'standard' => 4,
                'lesson_followup' => 1,
                'wanikani' => 1,
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['newCardLaneWeights']);

        $this->assertDatabaseCount('study_settings', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    /** @return array{standard: int, lessonFollowup: int, wanikani: int} */
    private function defaultLaneWeights(): array
    {
        return [
            'standard' => StudySettings::DEFAULT_STANDARD_LANE_WEIGHT,
            'lessonFollowup' => StudySettings::DEFAULT_LESSON_FOLLOWUP_LANE_WEIGHT,
            'wanikani' => StudySettings::DEFAULT_WANIKANI_LANE_WEIGHT,
        ];
    }
}
