<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Support\AdminCourseLineRenderingStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AdminCourseLineRenderingStorageTest extends TestCase
{
    public function test_disk_resolution_failure_is_reported_without_escaping(): void
    {
        Exceptions::fake();
        $failure = new RuntimeException('disk unavailable');
        Storage::shouldReceive('disk')->once()->with('missing')->andThrow($failure);
        config()->set('content_courses.audio_disk', 'missing');

        app(AdminCourseLineRenderingStorage::class)->deletePaths(['owned.mp3']);

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception === $failure,
        );
    }

    public function test_file_failure_is_reported_and_remaining_paths_are_deleted(): void
    {
        Exceptions::fake();
        $failure = new RuntimeException('delete unavailable');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with('first.mp3')->andThrow($failure);
        $disk->shouldReceive('delete')->once()->with('second.mp3')->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('media')->andReturn($disk);
        config()->set('content_courses.audio_disk', 'media');

        app(AdminCourseLineRenderingStorage::class)->deletePaths(['first.mp3', 'second.mp3']);

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception === $failure,
        );
    }
}
