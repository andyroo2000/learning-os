<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Actions\CalculateAchievementMetricsAction;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class AchievementStudyMetricFacts
{
    /** @return array{study_day:string,ended_at:CarbonInterface,category:string,conversation_ms:int,listening_ms:int,daily_audio_episode:?string} */
    public function forSession(StudyActivitySession $session): array
    {
        return [
            'study_day' => $session->ended_at->utc()->toDateString(),
            'ended_at' => $session->ended_at,
            'category' => $session->category->value,
            'conversation_ms' => $session->category === StudyActivityCategory::Conversation
                ? $session->duration_ms
                : 0,
            'listening_ms' => $session->category === StudyActivityCategory::Listen
                ? ($session->audio_playback_ms ?? 0)
                : 0,
            'daily_audio_episode' => $this->dailyAudioEpisode($session),
        ];
    }

    /**
     * @return array{
     *   doubleFeature:int,
     *   doubleFeatureReachedAt:?CarbonImmutable,
     *   repeatDays:int,
     *   repeatReachedAt:array<int, CarbonImmutable>
     * }
     */
    public function milestones(int $userId): array
    {
        $categoriesByDay = [];
        $doubleFeatureReachedAt = null;
        $daysByEpisode = [];
        $repeatReachedAt = [];
        $repeatDays = 0;

        $facts = AchievementStudySessionProjection::query()
            ->where('user_id', $userId)
            ->orderBy('ended_at')
            ->orderBy('study_activity_session_id')
            ->get(['study_day', 'ended_at', 'category', 'daily_audio_episode']);

        foreach ($facts as $fact) {
            $day = $fact->study_day->toDateString();
            $categoriesByDay[$day][$fact->category] = true;
            if ($doubleFeatureReachedAt === null && isset(
                $categoriesByDay[$day][StudyActivityCategory::Listen->value],
                $categoriesByDay[$day][StudyActivityCategory::Conversation->value],
            )) {
                $doubleFeatureReachedAt = CarbonImmutable::instance($fact->ended_at);
            }

            $episode = $fact->daily_audio_episode;
            if (! is_string($episode) || isset($daysByEpisode[$episode][$day])) {
                continue;
            }
            $daysByEpisode[$episode][$day] = true;
            $dayCount = count($daysByEpisode[$episode]);
            $repeatDays = max($repeatDays, $dayCount);
            $repeatReachedAt[$dayCount] ??= CarbonImmutable::instance($fact->ended_at);
        }

        return [
            'doubleFeature' => $doubleFeatureReachedAt === null ? 0 : 1,
            'doubleFeatureReachedAt' => $doubleFeatureReachedAt,
            'repeatDays' => $repeatDays,
            'repeatReachedAt' => $repeatReachedAt,
        ];
    }

    private function dailyAudioEpisode(StudyActivitySession $session): ?string
    {
        if ($session->activity !== StudyActivityKind::DailyAudio || $session->name === null) {
            return null;
        }

        $prefix = CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX;
        if (! str_starts_with($session->name, $prefix)) {
            return null;
        }

        $candidate = trim(substr($session->name, strlen($prefix)));

        return $candidate === '' ? null : $candidate;
    }
}
