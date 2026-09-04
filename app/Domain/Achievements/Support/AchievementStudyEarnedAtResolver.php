<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Actions\CalculateAchievementMetricsAction;
use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Values\AchievementMetricTarget;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;

final class AchievementStudyEarnedAtResolver
{
    /**
     * @var array<int, array{
     *     conversation: array<int, CarbonImmutable>,
     *     listening: array<int, CarbonImmutable>,
     *     doubleFeature: ?CarbonImmutable,
     *     repeat: array<int, CarbonImmutable>
     * }>
     */
    private array $achievementDates = [];

    public function date(AchievementMetricTarget $target): ?CarbonImmutable
    {
        $dates = $this->achievementDates($target);

        return match ($target->metricKey) {
            GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => $dates['conversation'][$target->threshold] ?? null,
            GetAchievementProgressAction::LISTENING_HOUR_METRIC => $dates['listening'][$target->threshold] ?? null,
            GetAchievementProgressAction::DOUBLE_FEATURE_METRIC => $dates['doubleFeature'],
            GetAchievementProgressAction::ON_REPEAT_METRIC => $dates['repeat'][$target->threshold] ?? null,
        };
    }

    /**
     * @return array{
     *     conversation: array<int, CarbonImmutable>,
     *     listening: array<int, CarbonImmutable>,
     *     doubleFeature: ?CarbonImmutable,
     *     repeat: array<int, CarbonImmutable>
     * }
     */
    private function achievementDates(AchievementMetricTarget $target): array
    {
        if (array_key_exists($target->userId, $this->achievementDates)) {
            return $this->achievementDates[$target->userId];
        }

        $conversationMilliseconds = 0;
        $listeningMilliseconds = 0;
        $conversation = [];
        $listening = [];
        $doubleFeature = null;
        $repeat = [];
        $categoriesByDay = [];
        $daysByEpisode = [];

        $sessions = StudyActivitySession::query()
            ->where('user_id', $target->userId)
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get([
                'id',
                'category',
                'activity',
                'name',
                'ended_at',
                'duration_ms',
                'audio_playback_ms',
            ]);

        foreach ($sessions as $session) {
            $endedAt = CarbonImmutable::instance($session->ended_at);
            $day = $endedAt->utc()->toDateString();
            $categoriesByDay[$day][$session->category->value] = true;
            $doubleFeature ??= $this->doubleFeatureCrossing($categoriesByDay[$day], $endedAt);

            if ($session->category === StudyActivityCategory::Conversation) {
                $this->recordHourCrossings(
                    $conversationMilliseconds,
                    $conversation,
                    $session->duration_ms,
                    $endedAt,
                );
            }
            if ($session->category === StudyActivityCategory::Listen) {
                $this->recordHourCrossings(
                    $listeningMilliseconds,
                    $listening,
                    $session->audio_playback_ms ?? 0,
                    $endedAt,
                );
            }
            $this->recordRepeatCrossing($daysByEpisode, $repeat, $session, $endedAt);
        }

        return $this->achievementDates[$target->userId] = [
            'conversation' => $conversation,
            'listening' => $listening,
            'doubleFeature' => $doubleFeature,
            'repeat' => $repeat,
        ];
    }

    /** @param array<string, true> $categories */
    private function doubleFeatureCrossing(array $categories, CarbonImmutable $endedAt): ?CarbonImmutable
    {
        return isset(
            $categories[StudyActivityCategory::Listen->value],
            $categories[StudyActivityCategory::Conversation->value],
        ) ? $endedAt : null;
    }

    /** @param array<int, CarbonImmutable> $crossings */
    private function recordHourCrossings(
        int &$elapsedMilliseconds,
        array &$crossings,
        int $durationMilliseconds,
        CarbonImmutable $endedAt,
    ): void {
        $previousHours = intdiv($elapsedMilliseconds, 3_600_000);
        $elapsedMilliseconds += $durationMilliseconds;
        $currentHours = intdiv($elapsedMilliseconds, 3_600_000);

        for ($hour = $previousHours + 1; $hour <= $currentHours; $hour++) {
            $crossings[$hour] = $endedAt;
        }
    }

    /**
     * @param  array<string, array<string, true>>  $daysByEpisode
     * @param  array<int, CarbonImmutable>  $repeat
     */
    private function recordRepeatCrossing(
        array &$daysByEpisode,
        array &$repeat,
        StudyActivitySession $session,
        CarbonImmutable $endedAt,
    ): void {
        $episode = $this->dailyAudioEpisode($session);
        $day = $endedAt->utc()->toDateString();
        if ($episode === null || isset($daysByEpisode[$episode][$day])) {
            return;
        }

        $daysByEpisode[$episode][$day] = true;
        $repeat[count($daysByEpisode[$episode])] ??= $endedAt;
    }

    private function dailyAudioEpisode(StudyActivitySession $session): ?string
    {
        if ($session->activity !== StudyActivityKind::DailyAudio) {
            return null;
        }

        if ($session->name === null) {
            return null;
        }

        if (! str_starts_with($session->name, CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX)) {
            return null;
        }

        $episode = trim(substr(
            $session->name,
            strlen(CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX),
        ));

        return $episode === '' ? null : $episode;
    }
}
