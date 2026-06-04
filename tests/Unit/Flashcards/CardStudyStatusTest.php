<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
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
}
