<?php

namespace App\Domain\Media\Services;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Support\StaticMediaSettings;
use DateTimeInterface;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use RuntimeException;

final class GoogleCloudStaticMediaObjectStore implements StaticMediaObjectStore
{
    private ?StorageClient $client = null;

    public function __construct(
        private readonly StaticMediaSettings $settings,
    ) {}

    public function exists(string $objectPath): bool
    {
        return $this->object($objectPath)->exists();
    }

    public function signedReadUrl(
        string $objectPath,
        DateTimeInterface $expiresAt,
        ?string $responseType = null,
    ): string {
        $options = ['version' => 'v4'];
        if ($responseType !== null) {
            $options['responseType'] = $responseType;
        }

        return $this->object($objectPath)->signedUrl($expiresAt, $options);
    }

    private function object(string $objectPath): StorageObject
    {
        $bucket = $this->settings->bucketName();
        if ($bucket === null) {
            throw new RuntimeException('GCS_BUCKET_NAME is not configured.');
        }

        return $this->client()
            ->bucket($bucket)
            ->object($objectPath);
    }

    private function client(): StorageClient
    {
        return $this->client ??= new StorageClient;
    }
}
