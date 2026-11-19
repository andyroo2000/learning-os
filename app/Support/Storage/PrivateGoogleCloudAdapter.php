<?php

namespace App\Support\Storage;

use Google\Cloud\Storage\Bucket;
use League\Flysystem\Config;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

final class PrivateGoogleCloudAdapter extends GoogleCloudStorageAdapter
{
    public function __construct(private Bucket $mediaBucket, private string $mediaPrefix)
    {
        parent::__construct($mediaBucket, $mediaPrefix, new UniformBucketLevelAccessVisibility, streamReads: true);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! $config->get('create_only', false)) {
            parent::writeStream($path, $contents, $config);

            return;
        }
        try {
            $this->mediaBucket->upload($contents, [
                'name' => $this->mediaPrefix.'/'.$path,
                'ifGenerationMatch' => 0,
                'metadata' => [
                    'contentType' => $config->get('mimetype', 'application/octet-stream'),
                    'cacheControl' => 'private, no-store',
                ],
            ]);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }
}
