<?php

namespace App\Domain\Study\Enums;

use App\Domain\Flashcards\Enums\CardStudyStatus;

enum StudyMasteryLevel: string
{
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
            $stabilityDays >= 365 => self::Burned,
            $stabilityDays >= 90 => self::Enlightened,
            $stabilityDays >= 30 => self::Master,
            $stabilityDays >= 7 => self::Guru,
            default => self::Apprentice,
        };
    }
}
