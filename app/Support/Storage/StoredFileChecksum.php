<?php

namespace App\Support\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class StoredFileChecksum
{
    public static function sha256(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot open stored file for verification.');
        }
        try {
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) === false) {
                throw new RuntimeException('Cannot verify stored file.');
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
