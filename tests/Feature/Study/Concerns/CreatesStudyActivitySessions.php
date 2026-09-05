<?php

namespace Tests\Feature\Study\Concerns;

use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesStudyActivitySessions
{
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
