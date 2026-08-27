<?php

namespace App\Domain\Study\Enums;

use App\Domain\Flashcards\Enums\CardStudyStatus;

enum StudyMasteryLevel: string
{
    public const int GURU_STABILITY_DAYS = 7;

    public const int MASTER_STABILITY_DAYS = 30;

    public const int ENLIGHTENED_STABILITY_DAYS = 90;

    public const int BURNED_STABILITY_DAYS = 365;

    case Apprentice = 'apprentice';
    case Guru = 'guru';
    case Master = 'master';
    case Enlightened = 'enlightened';
    case Burned = 'burned';

    /**
     * Mastery is a motivational projection of FSRS stability. It never schedules cards.
     *
     * @param  array<string, mixed>|null  $schedulerState
     */
    public static function fromFsrs(CardStudyStatus $status, ?array $schedulerState): self
    {
        if (in_array($status, [
            CardStudyStatus::New,
            CardStudyStatus::Learning,
            CardStudyStatus::Relearning,
        ], true)) {
            return self::Apprentice;
        }

        $stability = $schedulerState['stability'] ?? null;
        $stabilityDays = is_int($stability) || is_float($stability) ? (float) $stability : 0.0;

        return match (true) {
            $stabilityDays >= self::BURNED_STABILITY_DAYS => self::Burned,
            $stabilityDays >= self::ENLIGHTENED_STABILITY_DAYS => self::Enlightened,
            $stabilityDays >= self::MASTER_STABILITY_DAYS => self::Master,
            $stabilityDays >= self::GURU_STABILITY_DAYS => self::Guru,
            default => self::Apprentice,
        };
    }
}
