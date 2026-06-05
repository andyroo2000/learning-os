<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StudyImportJobStatusTest extends TestCase
{
    public function test_it_exposes_status_values(): void
    {
        $this->assertSame([
            'pending',
            'processing',
            'completed',
            'failed',
        ], StudyImportStatus::values());
    }

    public function test_it_normalizes_filter_values(): void
    {
        $this->assertSame(StudyImportStatus::Completed, StudyImportStatus::fromFilter(' COMPLETED '));
        $this->assertSame(StudyImportStatus::Completed, StudyImportStatus::fromFilter(StudyImportStatus::Completed));
    }

    public function test_it_rejects_blank_filter_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study import status filter must not be blank when provided.');

        StudyImportStatus::fromFilter('   ');
    }

    public function test_it_rejects_malformed_filter_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study import status filter must be one of: pending, processing, completed, failed.');

        StudyImportStatus::fromFilter('queued');
    }
}
