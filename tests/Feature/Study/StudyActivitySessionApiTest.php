<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySessionRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
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
        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
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

    public function test_it_validates_bounded_dates_enums_and_durations(): void
    {
        $this->signIn();

        $this->postJson('/api/study/activity-sessions/batch', [
            'sessions' => [[
                'clientSessionId' => 'bad',
                'category' => 'work',
                'activity' => 'coding',
                'source' => 'guess',
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
}
