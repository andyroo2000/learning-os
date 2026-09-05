<?php

namespace Tests\Feature\Study;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Study\Concerns\CreatesStudyActivitySessions;
use Tests\TestCase;

class StudyActivitySessionCompatibilityApiTest extends TestCase
{
    use CreatesStudyActivitySessions;
    use RefreshDatabase;

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
}
