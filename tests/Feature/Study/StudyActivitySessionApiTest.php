<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySessionRateLimiter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyActivitySessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/activity-sessions?from=2026-07-01T00:00:00Z&to=2026-08-01T00:00:00Z')
            ->assertUnauthorized();
        $this->getJson('/api/study/activity-analytics?timezone=America%2FNew_York&weekStartsOn=1')
            ->assertUnauthorized();
        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
            ->assertUnauthorized();
        $this->deleteJson('/api/study/activity-sessions/018f22d2-6d38-7000-8000-000000000001')
            ->assertUnauthorized();
    }

    public function test_it_upserts_retry_safe_sessions_and_lists_only_the_authenticated_users_range(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        StudyActivitySession::query()->forceCreate([
            'user_id' => $other->id,
            'client_session_id' => '018f22d2-6d38-7000-8000-000000000099',
            'category' => 'review',
            'activity' => 'card_review',
            'source' => 'automatic',
            'started_at' => '2026-07-28T10:00:00Z',
            'ended_at' => '2026-07-28T10:10:00Z',
            'duration_ms' => 600000,
        ]);

        $payload = [
            'sessions' => [[
                'clientSessionId' => '018f22d2-6d38-7000-8000-000000000001',
                'category' => 'create',
                'activity' => 'card_creation',
                'source' => 'manual',
                'name' => 'Episode 4 cards',
                'startedAt' => '2026-07-28T12:00:00.000Z',
                'endedAt' => '2026-07-28T13:00:00.000Z',
                'durationMs' => 3600000,
                'cardsCreated' => 12,
            ]],
        ];

        $this->postJson('/api/study/activity-sessions/batch', $payload)
            ->assertOk()
            ->assertJsonPath('0.category', 'create')
            ->assertJsonPath('0.origin', 'legacy')
            ->assertJsonPath('0.cardsCreated', 12);

        $payload['sessions'][0]['name'] = 'Episode 4 vocabulary';
        $this->postJson('/api/study/activity-sessions/batch', $payload)
            ->assertOk()
            ->assertJsonPath('0.name', 'Episode 4 vocabulary');

        $this->assertDatabaseCount('study_activity_sessions', 2);
        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => '018f22d2-6d38-7000-8000-000000000001',
            'name' => 'Episode 4 vocabulary',
        ]);

        $this->getJson('/api/study/activity-sessions?from=2026-07-28T00:00:00Z&to=2026-07-29T00:00:00Z')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.clientSessionId', '018f22d2-6d38-7000-8000-000000000001');
    }

    public function test_it_records_origin_without_allowing_retries_to_rewrite_provenance(): void
    {
        $user = $this->signIn();
        $clientSessionId = '018f22d2-6d38-7000-8000-000000000020';
        $session = [
            'clientSessionId' => $clientSessionId,
            'category' => 'conversation',
            'activity' => 'conversation',
            'source' => 'manual',
            'origin' => 'ios',
            'startedAt' => '2026-07-28T12:00:00Z',
            'endedAt' => '2026-07-28T13:00:00Z',
            'durationMs' => 3_600_000,
        ];

        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => [$session]])
            ->assertOk()
            ->assertJsonPath('0.origin', 'ios');

        $session['origin'] = 'web';
        $session['name'] = 'Retry from another client';
        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => [$session]])
            ->assertOk()
            ->assertJsonPath('0.origin', 'ios')
            ->assertJsonPath('0.name', 'Retry from another client');

        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $clientSessionId,
            'origin' => 'ios',
        ]);
    }

    public function test_it_treats_an_explicit_null_origin_as_legacy_for_optional_field_compatibility(): void
    {
        $this->signIn();

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => '018f22d2-6d38-7000-8000-000000000029',
                'category' => 'conversation',
                'activity' => 'conversation',
                'source' => 'manual',
                'origin' => null,
                'startedAt' => '2026-07-28T12:00:00Z',
                'endedAt' => '2026-07-28T13:00:00Z',
                'durationMs' => 3_600_000,
            ]],
        ])->assertOk()
            ->assertJsonPath('0.origin', 'legacy');
    }

    public function test_it_reserves_provider_and_system_origins_for_server_side_writers(): void
    {
        $this->signIn();

        foreach (['legacy', 'google_calendar', 'wanikani', 'system'] as $index => $origin) {
            $this->postJson('/api/study/activity-sessions/batch', [
                'sessions' => [[
                    'clientSessionId' => sprintf(
                        '018f22d2-6d38-7000-8000-%012d',
                        30 + $index,
                    ),
                    'category' => 'conversation',
                    'activity' => 'conversation',
                    'source' => 'manual',
                    'origin' => $origin,
                    'startedAt' => '2026-07-28T12:00:00Z',
                    'endedAt' => '2026-07-28T13:00:00Z',
                    'durationMs' => 3_600_000,
                ]],
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('sessions.0.origin');
        }

        $this->assertDatabaseCount('study_activity_sessions', 0);
    }

    public function test_it_rejects_server_owned_source_keys_from_public_sync_clients(): void
    {
        $this->signIn();

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => '018f22d2-6d38-7000-8000-000000000034',
                'category' => 'conversation',
                'activity' => 'conversation',
                'source' => 'manual',
                'origin' => 'web',
                'sourceKey' => str_repeat('a', 64),
                'startedAt' => '2026-07-28T12:00:00Z',
                'endedAt' => '2026-07-28T13:00:00Z',
                'durationMs' => 3_600_000,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.sourceKey');

        $this->assertDatabaseCount('study_activity_sessions', 0);
    }

    public function test_it_validates_bounded_dates_enums_and_durations(): void
    {
        $this->signIn();

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => 'bad',
                'category' => 'work',
                'activity' => 'coding',
                'source' => 'guess',
                'origin' => 'guess',
                'startedAt' => '2026-07-28T13:00:00Z',
                'endedAt' => '2026-07-28T12:00:00Z',
                'durationMs' => 86_400_001,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sessions.0.clientSessionId',
                'sessions.0.category',
                'sessions.0.activity',
                'sessions.0.source',
                'sessions.0.origin',
                'sessions.0.endedAt',
                'sessions.0.durationMs',
            ]);

        $this->getJson('/api/study/activity-sessions?from=2026-01-01T00:00:00Z&to=2026-07-01T00:00:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/study/activity-sessions?from=tomorrow&to=next%20week')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_it_rejects_category_mismatches_and_non_iso_timestamps(): void
    {
        $this->signIn();

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => '018f22d2-6d38-7000-8000-000000000002',
                'category' => 'immerse',
                'activity' => 'card_creation',
                'source' => 'automatic',
                'startedAt' => 'tomorrow',
                'endedAt' => '2026-07-28 13:00:00',
                'durationMs' => 300000,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sessions.0.startedAt',
                'sessions.0.endedAt',
            ]);

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => '018f22d2-6d38-7000-8000-000000000002',
                'category' => 'immerse',
                'activity' => 'card_creation',
                'source' => 'automatic',
                'startedAt' => '2026-07-28T12:00:00-04:00',
                'endedAt' => '2026-07-28T12:05:00-04:00',
                'durationMs' => 300000,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.category');
    }

    public function test_it_accepts_listen_conversation_and_wanikani_as_distinct_categories(): void
    {
        $this->signIn();

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [
                [
                    'clientSessionId' => '018f22d2-6d38-7000-8000-000000000009',
                    'category' => 'listen',
                    'activity' => 'daily_audio',
                    'source' => 'automatic',
                    'name' => 'Daily drill',
                    'startedAt' => '2026-07-28T11:30:00Z',
                    'endedAt' => '2026-07-28T12:00:00Z',
                    'durationMs' => 1_800_000,
                    'audioPlaybackMs' => 1_800_000,
                ],
                [
                    'clientSessionId' => '018f22d2-6d38-7000-8000-000000000010',
                    'category' => 'conversation',
                    'activity' => 'conversation',
                    'source' => 'manual',
                    'name' => 'iTalki',
                    'startedAt' => '2026-07-28T12:00:00Z',
                    'endedAt' => '2026-07-28T13:00:00Z',
                    'durationMs' => 3_600_000,
                ],
                [
                    'clientSessionId' => '018f22d2-6d38-7000-8000-000000000011',
                    'category' => 'wanikani',
                    'activity' => 'wanikani_review',
                    'source' => 'manual',
                    'startedAt' => '2026-07-28T13:00:00Z',
                    'endedAt' => '2026-07-28T13:20:00Z',
                    'durationMs' => 1_200_000,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('0.category', 'listen')
            ->assertJsonPath('1.category', 'conversation')
            ->assertJsonPath('2.category', 'wanikani');
    }

    public function test_it_canonicalizes_the_legacy_daily_audio_review_pair(): void
    {
        $user = $this->signIn();

        $this->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/activity-sessions/batch', [
                'sessions' => [[
                    'clientSessionId' => '018f22d2-6d38-7000-8000-000000000019',
                    'category' => ' REVIEW ',
                    'activity' => ' DAILY_AUDIO ',
                    'source' => 'automatic',
                    'origin' => ' IOS ',
                    'startedAt' => '2026-07-28T12:00:00Z',
                    'endedAt' => '2026-07-28T12:30:00Z',
                    'durationMs' => 1_800_000,
                    'audioPlaybackMs' => 1_800_000,
                ]],
            ])->assertOk()
            ->assertJsonPath('0.category', 'listen')
            ->assertJsonPath('0.origin', 'ios');

        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => '018f22d2-6d38-7000-8000-000000000019',
            'category' => 'listen',
            'activity' => 'daily_audio',
        ]);
    }

    public function test_it_does_not_overwrite_an_existing_automatic_session(): void
    {
        $user = $this->signIn();
        $clientSessionId = '018f22d2-6d38-7000-8000-000000000012';
        $this->createSession($user, [
            'client_session_id' => $clientSessionId,
            'source' => 'automatic',
            'name' => 'Original review',
        ]);

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => strtoupper($clientSessionId),
                'category' => 'conversation',
                'activity' => 'conversation',
                'source' => 'manual',
                'name' => 'Changed lesson',
                'startedAt' => '2026-07-28T12:00:00Z',
                'endedAt' => '2026-07-28T13:00:00Z',
                'durationMs' => 3_600_000,
            ]],
        ])->assertOk()
            ->assertJsonPath('0.source', 'automatic')
            ->assertJsonPath('0.name', 'Original review');

        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $clientSessionId,
            'source' => 'automatic',
            'name' => 'Original review',
        ]);
    }

    public function test_it_does_not_upgrade_an_existing_manual_session_to_automatic(): void
    {
        $user = $this->signIn();
        $clientSessionId = '018f22d2-6d38-7000-8000-000000000013';
        $this->createSession($user, [
            'client_session_id' => $clientSessionId,
            'source' => 'manual',
            'name' => 'Original lesson',
        ]);

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => $clientSessionId,
                'category' => 'conversation',
                'activity' => 'conversation',
                'source' => 'automatic',
                'name' => 'Updated lesson',
                'startedAt' => '2026-07-28T12:00:00Z',
                'endedAt' => '2026-07-28T13:00:00Z',
                'durationMs' => 3_600_000,
            ]],
        ])->assertOk()
            ->assertJsonPath('0.source', 'manual')
            ->assertJsonPath('0.name', 'Updated lesson');

        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $clientSessionId,
            'source' => 'manual',
            'name' => 'Updated lesson',
        ]);
    }

    public function test_identifier_backfill_canonicalizes_and_deduplicates_existing_rows(): void
    {
        $user = User::factory()->create();
        $canonicalId = '018f22d2-6d38-7000-8000-abcdefabcdef';
        $this->createSession($user, [
            'client_session_id' => strtoupper($canonicalId),
            'name' => 'First copy',
        ]);
        $this->createSession($user, [
            'client_session_id' => $canonicalId,
            'name' => 'Canonical copy',
        ]);
        $migration = require database_path(
            'migrations/2026_07_29_030000_normalize_study_activity_session_ids.php',
        );

        $migration->up();

        $this->assertDatabaseCount('study_activity_sessions', 1);
        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $canonicalId,
            'name' => 'Canonical copy',
        ]);
    }

    public function test_daily_audio_category_rollback_restores_the_legacy_contract(): void
    {
        $user = User::factory()->create();
        $this->createSession($user, [
            'client_session_id' => 'legacy-daily-audio',
            'category' => 'review',
            'activity' => 'daily_audio',
        ]);
        $this->createSession($user, [
            'client_session_id' => 'card-review',
            'category' => 'review',
            'activity' => 'card_review',
        ]);
        $migration = require database_path(
            'migrations/2026_07_30_010000_backfill_daily_audio_study_activity_category.php',
        );

        $migration->up();

        $this->assertDatabaseHas('study_activity_sessions', [
            'client_session_id' => 'legacy-daily-audio',
            'category' => 'listen',
        ]);
        $this->assertDatabaseHas('study_activity_sessions', [
            'client_session_id' => 'card-review',
            'category' => 'review',
        ]);
        $this->createSession($user, [
            'client_session_id' => 'post-deploy-daily-audio',
            'category' => 'listen',
            'activity' => 'daily_audio',
        ]);

        $migration->down();

        $this->assertDatabaseHas('study_activity_sessions', [
            'client_session_id' => 'legacy-daily-audio',
            'category' => 'review',
        ]);
        $this->assertDatabaseHas('study_activity_sessions', [
            'client_session_id' => 'post-deploy-daily-audio',
            'category' => 'review',
        ]);
    }

    public function test_it_returns_cross_device_activity_analytics_in_the_users_timezone(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-07-28T16:00:00Z'));

        try {
            $this->createSession($user, [
                'client_session_id' => 'analytics-review',
                'category' => 'review',
                'activity' => 'card_review',
                'source' => 'automatic',
                'started_at' => '2026-07-28T14:00:00Z',
                'ended_at' => '2026-07-28T14:30:00Z',
                'duration_ms' => 1_800_000,
            ]);
            $this->createSession($user, [
                'client_session_id' => 'analytics-conversation',
                'category' => 'conversation',
                'activity' => 'conversation',
                'source' => 'manual',
                'started_at' => '2026-07-27T17:00:00Z',
                'ended_at' => '2026-07-27T18:00:00Z',
                'duration_ms' => 3_600_000,
            ]);
            $this->createSession($user, [
                'client_session_id' => 'analytics-sunday-boundary',
                'category' => 'review',
                'activity' => 'card_review',
                'source' => 'manual',
                'started_at' => '2026-07-26T17:00:00Z',
                'ended_at' => '2026-07-26T18:00:00Z',
                'duration_ms' => 3_600_000,
            ]);
            $this->createSession($user, [
                'client_session_id' => 'analytics-midnight',
                'category' => 'immerse',
                'activity' => 'tv',
                'source' => 'manual',
                'started_at' => '2026-07-28T03:50:00Z',
                'ended_at' => '2026-07-28T04:10:00Z',
                'duration_ms' => 1_200_000,
            ]);
            $this->createSession($other, [
                'client_session_id' => 'analytics-other-user',
                'category' => 'review',
                'activity' => 'card_review',
                'source' => 'automatic',
                'started_at' => '2026-07-28T14:00:00Z',
                'ended_at' => '2026-07-28T15:00:00Z',
                'duration_ms' => 3_600_000,
            ]);

            $response = $this->getJson(
                '/api/study/activity-analytics?timezone=America%2FNew_York'
                    .'&weekStartsOn=1&adaptiveAllTime=1',
            )->assertOk()
                ->assertJsonPath('anchorDate', '2026-07-28')
                ->assertJsonPath('timezone', 'America/New_York')
                ->assertJsonCount(5, 'ranges')
                ->assertJsonPath('ranges.0.key', 'today')
                ->assertJsonPath('ranges.0.totalMs', 2_400_000)
                ->assertJsonPath('ranges.0.categories.review', 1_800_000)
                ->assertJsonPath('ranges.0.categories.immerse', 600_000)
                ->assertJsonPath('ranges.1.key', 'week')
                ->assertJsonPath('ranges.1.totalMs', 10_200_000)
                ->assertJsonPath('ranges.4.bucketUnit', 'day');

            $response->assertJsonStructure([
                'generatedAt',
                'anchorDate',
                'timezone',
                'ranges' => [[
                    'key',
                    'startsAt',
                    'endsAt',
                    'bucketUnit',
                    'bucketStep',
                    'totalMs',
                    'categories' => ['review', 'listen', 'create', 'immerse', 'conversation', 'wanikani'],
                    'buckets' => [[
                        'startsAt',
                        'endsAt',
                        'totalMs',
                        'categories' => ['review', 'listen', 'create', 'immerse', 'conversation', 'wanikani'],
                    ]],
                ]],
            ]);

            $this->getJson(
                '/api/study/activity-analytics?timezone=America%2FNew_York&weekStartsOn=2',
            )->assertOk()
                ->assertJsonPath('ranges.1.key', 'week')
                ->assertJsonPath('ranges.1.totalMs', 6_600_000)
                ->assertJsonPath('ranges.4.bucketUnit', 'year');

            $this->getJson(
                '/api/study/activity-analytics?timezone=America%2FNew_York'
                    .'&weekStartsOn=2&anchorDate=2026-07-27',
            )->assertOk()
                ->assertJsonPath('anchorDate', '2026-07-27')
                ->assertJsonPath('ranges.0.startsAt', '2026-07-27T04:00:00.000000Z')
                ->assertJsonPath('ranges.0.endsAt', '2026-07-28T04:00:00.000000Z')
                ->assertJsonPath('ranges.0.totalMs', 4_200_000);
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_validates_activity_analytics_timezone_and_week_start(): void
    {
        $this->signIn();

        $this->getJson(
            '/api/study/activity-analytics?timezone=Moon%2FBase'
                .'&weekStartsOn=0&anchorDate=07-28-2026'
                .'&adaptiveAllTime=maybe',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'timezone',
                'weekStartsOn',
                'anchorDate',
                'adaptiveAllTime',
            ]);

        $this->getJson(
            '/api/study/activity-analytics?timezone=UTC'
                .'&weekStartsOn=1&anchorDate[]=2026-07-28',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['anchorDate']);

        $this->getJson(
            '/api/study/activity-analytics?timezone=UTC'
                .'&weekStartsOn=1&anchorDate=tomorrow',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['anchorDate']);
    }

    public function test_it_deletes_only_manual_or_calendar_sessions_and_is_retry_safe(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $manualId = '018f22d2-6d38-7000-8000-000000000020';
        $automaticId = '018f22d2-6d38-7000-8000-000000000021';
        $otherId = '018f22d2-6d38-7000-8000-000000000022';
        $providerId = '018f22d2-6d38-7000-8000-000000000023';
        $this->createSession($user, [
            'client_session_id' => $manualId,
            'source' => 'manual',
        ]);
        $this->createSession($user, [
            'client_session_id' => $automaticId,
            'source' => 'automatic',
        ]);
        $this->createSession($other, [
            'client_session_id' => $otherId,
            'source' => 'manual',
        ]);
        $this->createSession($user, [
            'client_session_id' => $providerId,
            'source' => 'calendar',
            'origin' => 'google_calendar',
        ]);

        $this->deleteJson('/api/study/activity-sessions/'.strtoupper($manualId))
            ->assertNoContent();
        $this->deleteJson('/api/study/activity-sessions/'.$manualId)
            ->assertNoContent();
        $this->deleteJson('/api/study/activity-sessions/'.$automaticId)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Automatically recorded or provider-managed study activity cannot be deleted.');
        $this->deleteJson('/api/study/activity-sessions/'.$providerId)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Automatically recorded or provider-managed study activity cannot be deleted.');
        $this->deleteJson('/api/study/activity-sessions/'.$otherId)
            ->assertNoContent();

        $this->assertDatabaseMissing('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $manualId,
        ]);
        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $automaticId,
        ]);
        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $other->id,
            'client_session_id' => $otherId,
        ]);
        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $providerId,
        ]);
    }

    public function test_it_rejects_duplicate_client_session_ids_within_a_batch(): void
    {
        $this->signIn();
        $session = [
            'clientSessionId' => '018f22d2-6d38-7000-8000-000000000003',
            'category' => 'review',
            'activity' => 'card_review',
            'source' => 'automatic',
            'startedAt' => '2026-07-28T12:00:00Z',
            'endedAt' => '2026-07-28T12:05:00Z',
            'durationMs' => 300000,
        ];

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [$session, $session],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sessions.0.clientSessionId',
                'sessions.1.clientSessionId',
            ]);

        $this->assertDatabaseCount('study_activity_sessions', 0);
    }

    public function test_it_rate_limits_session_writes_by_user(): void
    {
        $limiter = new StudyActivitySessionRateLimiter;
        $user = $this->signIn();
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $key = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);
            RateLimiter::for(
                StudyActivitySessionRateLimiter::NAME,
                fn (Request $request): Limit => Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor(
                        $request->user()?->getAuthIdentifier(),
                        $request->ip(),
                    ),
                ),
            );

            $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
                ->assertUnprocessable();
            $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
                ->assertUnprocessable();
            $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '2')
                ->assertHeader('X-RateLimit-Remaining', '0');
        } finally {
            RateLimiter::clear($key);
            RateLimiter::for(
                StudyActivitySessionRateLimiter::NAME,
                fn (Request $request): Limit => $limiter->limit($request),
            );
        }
    }

    /** @param array<string, mixed> $overrides */
    private function createSession(User $user, array $overrides): StudyActivitySession
    {
        return StudyActivitySession::query()->forceCreate(array_merge([
            'user_id' => $user->id,
            'client_session_id' => 'session-'.Str::ulid(),
            'category' => 'review',
            'activity' => 'card_review',
            'source' => 'manual',
            'started_at' => '2026-07-28T12:00:00Z',
            'ended_at' => '2026-07-28T12:10:00Z',
            'duration_ms' => 600_000,
        ], $overrides));
    }
}
