<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
