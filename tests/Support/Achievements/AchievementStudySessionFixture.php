<?php

namespace Tests\Support\Achievements;

use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use Illuminate\Support\Carbon;

final class AchievementStudySessionFixture
{
    public StudyActivityCategory $category;

    public StudyActivityKind $activity;

    public int $durationMs;

    public Carbon $endedAt;

    public string $name;

    public ?int $audioPlaybackMs;

    private function __construct() {}

    public static function conversation(int $durationMs, Carbon $endedAt): self
    {
        $fixture = new self;
        $fixture->category = StudyActivityCategory::Conversation;
        $fixture->activity = StudyActivityKind::Conversation;
        $fixture->durationMs = $durationMs;
        $fixture->endedAt = $endedAt;
        $fixture->name = 'Conversation';
        $fixture->audioPlaybackMs = null;

        return $fixture;
    }

    public static function dailyAudio(int $durationMs, Carbon $endedAt, string $name, int $audioPlaybackMs): self
    {
        $fixture = new self;
        $fixture->category = StudyActivityCategory::Listen;
        $fixture->activity = StudyActivityKind::DailyAudio;
        $fixture->durationMs = $durationMs;
        $fixture->endedAt = $endedAt;
        $fixture->name = $name;
        $fixture->audioPlaybackMs = $audioPlaybackMs;

        return $fixture;
    }
}
