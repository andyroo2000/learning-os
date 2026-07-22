<?php

namespace App\Domain\Media\Services;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Contracts\StaticMediaObjectWriter;
use App\Domain\Media\Support\StaticMediaSettings;
use DateTimeInterface;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use RuntimeException;

final class GoogleCloudStaticMediaObjectStore implements StaticMediaObjectStore, StaticMediaObjectWriter
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

    public function putPublic(string $objectPath, string $contents, string $contentType): void
    {
        $bucket = $this->settings->bucketName();
        if ($bucket === null) {
            throw new RuntimeException('GCS_BUCKET_NAME is not configured.');
        }

        $this->client()->bucket($bucket)->upload($contents, [
            'name' => $objectPath,
            'predefinedAcl' => 'publicRead',
            'metadata' => [
                'contentType' => $contentType,
                'cacheControl' => 'public, max-age=31536000, immutable',
            ],
        ]);
    }

    public function read(string $objectPath): string
    {
        return $this->object($objectPath)->downloadAsString();
    }

    public function delete(string $objectPath): void
    {
        $object = $this->object($objectPath);
        if ($object->exists()) {
            $object->delete();
        }
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
