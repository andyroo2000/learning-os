<?php

namespace Tests\Unit\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Enums\StudyMasteryLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyMasteryLevelTest extends TestCase
{
    #[DataProvider('fsrsLevels')]
    public function test_it_projects_fsrs_stability_without_changing_scheduling(
        CardStudyStatus $status,
        float $stability,
        StudyMasteryLevel $expected,
    ): void {
        $this->assertSame(
            $expected,
            StudyMasteryLevel::fromFsrs($status, ['stability' => $stability]),
        );
    }

    /**
     * @return array<string, array{CardStudyStatus, float, StudyMasteryLevel}>
     */
    public static function fsrsLevels(): array
    {
        return [
            'new cards remain apprentice' => [CardStudyStatus::New, 500, StudyMasteryLevel::Apprentice],
            'learning cards remain apprentice' => [CardStudyStatus::Learning, 500, StudyMasteryLevel::Apprentice],
            'relearning cards remain apprentice' => [CardStudyStatus::Relearning, 500, StudyMasteryLevel::Apprentice],
            'short stability is apprentice' => [CardStudyStatus::Review, 6.99, StudyMasteryLevel::Apprentice],
            'one week is guru' => [CardStudyStatus::Review, 7, StudyMasteryLevel::Guru],
            'one month is master' => [CardStudyStatus::Review, 30, StudyMasteryLevel::Master],
            'three months is enlightened' => [CardStudyStatus::Review, 90, StudyMasteryLevel::Enlightened],
            'one year is burned' => [CardStudyStatus::Review, 365, StudyMasteryLevel::Burned],
        ];
    }
}
