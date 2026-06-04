<?php

namespace App\Domain\Sync\Data;

use App\Domain\Sync\Values\SyncFeedMetadata;

final readonly class RecordSyncFeedEntryData
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function __construct(
        public int $userId,
        public string $domain,
        public string $resourceType,
        public string $resourceId,
        public string $operation,
        public ?array $payload = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromInput(
        int $userId,
        string $domain,
        string $resourceType,
        string $resourceId,
        string $operation,
        ?array $payload = null,
    ): self {
        return new self(
            userId: $userId,
            domain: SyncFeedMetadata::normalize($domain),
            resourceType: SyncFeedMetadata::normalize($resourceType),
            resourceId: SyncFeedMetadata::normalize($resourceId),
            operation: SyncFeedMetadata::normalize($operation),
            payload: $payload,
        );
    }
}
