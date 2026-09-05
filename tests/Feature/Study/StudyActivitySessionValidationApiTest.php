<?php

namespace Tests\Feature\Study;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyActivitySessionValidationApiTest extends TestCase
{
    use RefreshDatabase;

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
}
