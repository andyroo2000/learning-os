<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CardStudyStatusTest extends TestCase
{
    public function test_it_exposes_status_values(): void
    {
        $this->assertSame([
            'new',
            'learning',
            'review',
            'relearning',
            'suspended',
            'buried',
        ], CardStudyStatus::values());
    }

    public function test_it_normalizes_filter_values(): void
    {
        $this->assertSame(CardStudyStatus::Review, CardStudyStatus::fromFilter(' REVIEW '));
        $this->assertSame(CardStudyStatus::Review, CardStudyStatus::fromFilter(CardStudyStatus::Review));
    }

    public function test_it_rejects_blank_filter_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status filter must not be blank when provided.');

        CardStudyStatus::fromFilter('   ');
    }

    public function test_it_rejects_malformed_filter_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status filter must be one of: new, learning, review, relearning, suspended, buried.');

        CardStudyStatus::fromFilter('queued');
    }
}
