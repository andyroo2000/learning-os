<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Exceptions\MediaAssetConflictException;
use PHPUnit\Framework\TestCase;

class MediaAssetConflictExceptionTest extends TestCase
{
    public function test_unresolved_storage_conflicts_are_visible_to_the_current_user(): void
    {
        $exception = MediaAssetConflictException::unresolvedStorageConflict();

        $this->assertFalse($exception->shouldBeHiddenFrom(123));
        $this->assertSame('media_asset_storage_conflict', $exception->reason());
    }
}
