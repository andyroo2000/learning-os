<?php

namespace Tests\Feature\Study;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Study\Concerns\CreatesStudyActivitySessions;
use Tests\TestCase;

class DeleteStudyActivitySessionApiTest extends TestCase
{
    use CreatesStudyActivitySessions;
    use RefreshDatabase;

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
}
