<?php

namespace App\Support\Storage;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use RuntimeException;

final class PrivateGoogleCloudFilesystem
{
    public function make(array $config): FilesystemAdapter
    {
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $prefix = trim((string) ($config['gcs_prefix'] ?? ''), '/');
        if ($bucket === '' || $prefix === '') {
            throw new RuntimeException('Private media requires a bucket and an environment-specific prefix.');
        }
        $adapter = new PrivateGoogleCloudAdapter(
            app(StorageClient::class)->bucket($bucket),
            $prefix,
        );
        // The dedicated bucket enforces public access prevention. Playback stays
        // behind the existing authenticated API, including Journal ownership checks.
        $options = ['visibility' => 'private', 'metadata' => ['cacheControl' => 'private, no-store']];

        return new FilesystemAdapter(new Filesystem($adapter, $options), $adapter, $config);
    }
}
