<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\Results\ReviewCardBatchResult;
use PHPUnit\Framework\TestCase;

class ReviewCardBatchResultTest extends TestCase
{
    public function test_with_created_events_marks_the_result_as_creating_events(): void
    {
        $reviewEvents = collect();

        $result = ReviewCardBatchResult::withCreatedEvents($reviewEvents);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertSame($reviewEvents, $result->reviewEvents);
    }

    public function test_without_created_events_marks_the_result_as_not_creating_events(): void
    {
        $reviewEvents = collect();

        $result = ReviewCardBatchResult::withoutCreatedEvents($reviewEvents);

        $this->assertFalse($result->hasCreatedEvents);
        $this->assertSame($reviewEvents, $result->reviewEvents);
    }
}
