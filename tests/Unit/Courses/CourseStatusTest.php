<?php

namespace Tests\Unit\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use PHPUnit\Framework\TestCase;

class CourseStatusTest extends TestCase
{
    public function test_it_exposes_status_values(): void
    {
        $this->assertSame(['draft', 'generating', 'ready', 'error'], CourseStatus::values());
    }
}
