<?php

namespace App\Domain\Flashcards\Enums;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Enums\StudyMasteryLevel;

enum CardProgressionUnlockRequirement: string
{
    case SuccessfulRetrieval = 'successful_retrieval';
    case Guru = 'guru';
    case Master = 'master';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $requirement): string => $requirement->value,
            self::cases(),
        );
    }

    public function isSatisfiedByMastery(Card $card): bool
    {
        if ($this === self::SuccessfulRetrieval) {
            return false;
        }

        $mastery = StudyMasteryLevel::fromFsrs(
            $card->study_status ?? CardStudyStatus::New,
            $card->scheduler_state,
        );

        return match ($this) {
            self::Guru => in_array($mastery, [
                StudyMasteryLevel::Guru,
                StudyMasteryLevel::Master,
                StudyMasteryLevel::Enlightened,
                StudyMasteryLevel::Burned,
            ], true),
            self::Master => in_array($mastery, [
                StudyMasteryLevel::Master,
                StudyMasteryLevel::Enlightened,
                StudyMasteryLevel::Burned,
            ], true),
            self::SuccessfulRetrieval => false,
        };
    }
}
