<?php

namespace App\Support\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class VerifiedMediaCopy
{
    public function copy(FilesystemAdapter $source, FilesystemAdapter $target, string $path, bool $verifyOnly): int
    {
        $size = $source->size($path);
        $checksum = $source->checksum($path, ['checksum_algo' => 'md5']);
        if (! $target->exists($path)) {
            if ($verifyOnly) {
                throw new RuntimeException("Remote file is missing: {$path}");
            }
            $this->upload($source, $target, $path);
        }
        $this->assertMatches($target, $path, $size, $checksum);
        // Never delete a source that changed while it was being copied.
        $this->assertMatches($source, $path, $size, $checksum);

        return $size;
    }

    private function upload(FilesystemAdapter $source, FilesystemAdapter $target, string $path): void
    {
        $stream = $source->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Cannot read source: {$path}");
        }
        try {
            if (! $target->put($path, $stream, ['visibility' => 'private', 'create_only' => true, 'mimetype' => $source->mimeType($path)])) {
                throw new RuntimeException("Upload failed: {$path}");
            }
        } finally {
            fclose($stream);
        }
    }

    private function assertMatches(FilesystemAdapter $disk, string $path, int $size, string $checksum): void
    {
        $matches = $disk->size($path) === $size
            && $disk->checksum($path, ['checksum_algo' => 'md5']) === $checksum;
        if (! $matches) {
            throw new RuntimeException("Checksum or size mismatch: {$path}");
        }
    }
}
