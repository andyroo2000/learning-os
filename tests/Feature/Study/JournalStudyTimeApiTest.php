<?php

namespace Tests\Feature\Study;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalStudyTimeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_is_retry_safe_writing_with_legacy_readback(): void
    {
        $user = $this->signIn();
        $payload = ['sessions' => [[
            'clientSessionId' => '018f22d2-6d38-7000-8000-000000000001',
            'activity' => 'journal',
            'source' => 'automatic',
            'origin' => 'web',
            'name' => 'Journal',
            'startedAt' => '2026-11-13T12:00:00.000Z',
            'endedAt' => '2026-11-13T12:02:00.000Z',
            'durationMs' => 120000,
        ]]];
        $this->postJson('/api/study/activity-sessions/batch', $payload, ['X-Study-Writing' => '1'])
            ->assertOk()->assertJsonPath('0.activity', 'journal')->assertJsonPath('0.category', 'writing');
        $this->postJson('/api/study/activity-sessions/batch', $payload)
            ->assertOk()->assertJsonPath('0.activity', 'other')->assertJsonPath('0.category', 'immerse');
        $legacy = $this->getJson('/api/study/activity-sessions?from=2026-11-13T00:00:00Z&to=2026-11-14T00:00:00Z')
            ->assertOk()->assertJsonPath('0.activity', 'other')->assertJsonPath('0.category', 'immerse')
            ->json('0');
        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => [$legacy]])
            ->assertOk();
        $this->assertDatabaseCount('study_activity_sessions', 1);
        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id, 'activity' => 'journal', 'category' => 'writing', 'duration_ms' => 120000,
        ]);
        $this->getJson('/api/study/activity-analytics?timezone=UTC&weekStartsOn=1&anchorDate=2026-11-13')
            ->assertOk()->assertJsonPath('ranges.0.categories.writing', 120000);
    }
}
