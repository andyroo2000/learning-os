<?php

namespace App\Console\Support;

use App\Domain\Media\Models\MediaAsset;
use RuntimeException;

final class ConvoLabMediaStoragePath
{
    public string $value;

    private string $sourceId;

    private function __construct() {}

    public static function fromMedia(object $media): self
    {
        $path = new self;
        $path->sourceId = (string) $media->id;
        $path->value = is_string($media->storagePath)
            ? trim(str_replace('\\', '/', $media->storagePath))
            : '';
        $path->assertSafe();

        return $path;
    }

    private function assertSafe(): void
    {
        $this->assertPresent();
        $this->assertRelative();
        $this->assertNoNullByte();
        $this->assertNoAbsolutePrefix();
        $this->assertNoTraversal();
        $this->assertStudyMediaPrefix();
        $this->assertLength();
    }

    private function assertPresent(): void
    {
        if ($this->value === '') {
            $this->reject();
        }
    }

    private function assertRelative(): void
    {
        if (ltrim($this->value, '/') !== $this->value) {
            $this->reject();
        }
    }

    private function assertNoNullByte(): void
    {
        if (str_contains($this->value, "\0")) {
            $this->reject();
        }
    }

    private function assertNoAbsolutePrefix(): void
    {
        if (preg_match(MediaAsset::PATH_ABSOLUTE_PATTERN, $this->value) === 1) {
            $this->reject();
        }
    }

    private function assertNoTraversal(): void
    {
        if (preg_match(MediaAsset::PATH_TRAVERSAL_PATTERN, $this->value) === 1) {
            $this->reject();
        }
    }

    private function assertStudyMediaPrefix(): void
    {
        if (! str_starts_with($this->value, 'study-media/')) {
            $this->reject();
        }
    }

    private function assertLength(): void
    {
        if (strlen($this->value) > MediaAsset::MAX_PATH_LENGTH) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new RuntimeException("Convo Lab media [{$this->sourceId}] has an unsafe storage path.");
    }
}
