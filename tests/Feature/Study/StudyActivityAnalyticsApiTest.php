<?php

namespace Tests\Feature\Study;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Study\Concerns\CreatesStudyActivitySessions;
use Tests\TestCase;

class StudyActivityAnalyticsApiTest extends TestCase
{
    use CreatesStudyActivitySessions;
    use RefreshDatabase;

    public function test_it_returns_cross_device_activity_analytics_in_the_users_timezone(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-07-28T16:00:00Z'));

        try {
            $this->createAnalyticsSessions($user, $other);
            $this->assertActivityAnalyticsResponses();
        } finally {
            $this->travelBack();
        }
    }

    private function createAnalyticsSessions(User $user, User $other): void
    {
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
    }

    private function assertActivityAnalyticsResponses(): void
    {
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
}
