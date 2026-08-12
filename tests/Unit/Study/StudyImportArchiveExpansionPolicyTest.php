<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyImportArchiveExpansionPolicy;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class StudyImportArchiveExpansionPolicyTest extends TestCase
{
    public function test_defaults_preserve_the_reviewed_archive_compatibility_headroom(): void
    {
        $policy = app(StudyImportArchiveExpansionPolicy::class);

        $this->assertSame(256 * 1024 * 1024, $policy->maxCollectionDatabaseBytes());
        $this->assertSame(16 * 1024 * 1024, $policy->maxMediaManifestBytes());
        $this->assertSame(100 * 1024 * 1024, $policy->maxIndividualMediaBytes());
        $this->assertSame(4 * 1024 * 1024 * 1024, $policy->maxTotalMediaBytes());
    }

    public function test_it_adds_media_bytes_at_the_inclusive_budget_boundary(): void
    {
        Config::set('study_import.archive_expansion.max_total_media_bytes', 10);

        $this->assertSame(10, app(StudyImportArchiveExpansionPolicy::class)->addMediaBytes(6, 4));
    }

    public function test_it_rejects_cumulative_media_bytes_without_overflowing(): void
    {
        Config::set('study_import.archive_expansion.max_total_media_bytes', PHP_INT_MAX);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study import media expansion exceeds its configured byte budget.');

        app(StudyImportArchiveExpansionPolicy::class)->addMediaBytes(PHP_INT_MAX - 2, 3);
    }

    public function test_it_rejects_non_positive_or_non_integer_configuration(): void
    {
        Config::set('study_import.archive_expansion.max_collection_database_bytes', '1.5');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Study import archive expansion limit "max_collection_database_bytes" must be a positive integer.',
        );

        app(StudyImportArchiveExpansionPolicy::class)->maxCollectionDatabaseBytes();
    }
}
