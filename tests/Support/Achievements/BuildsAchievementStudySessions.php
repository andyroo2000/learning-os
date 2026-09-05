<?php

namespace Tests\Support\Achievements;

use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait BuildsAchievementStudySessions
{
    private function conversationSession(
        User $user,
        int $durationMs,
        ?Carbon $endedAt = null,
    ): StudyActivitySession {
        return $this->studySession(
            $user,
            AchievementStudySessionFixture::conversation($durationMs, $endedAt ?? now()),
        );
    }

    private function studySession(User $user, AchievementStudySessionFixture $fixture): StudyActivitySession
    {
        return StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => (string) Str::ulid(),
            'category' => $fixture->category,
            'activity' => $fixture->activity,
            'source' => StudyActivitySource::Manual,
            'origin' => StudyActivityOrigin::Web,
            'name' => $fixture->name,
            'started_at' => $fixture->endedAt->copy()->subMilliseconds($fixture->durationMs),
            'ended_at' => $fixture->endedAt,
            'duration_ms' => $fixture->durationMs,
            'audio_playback_ms' => $fixture->audioPlaybackMs,
        ]);
    }
}
