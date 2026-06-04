<?php

namespace App\Domain\Sync\Exceptions;

use RuntimeException;

final class StaleSyncFeedCheckpointException extends RuntimeException
{
    public const REASON = 'stale_sync_checkpoint';

    public const REQUIRED_ACTION = 'full_resync';

    private function __construct(
        private readonly int $afterCheckpoint,
        private readonly int $oldestAvailableCheckpoint,
        private readonly ?string $domain,
        private readonly ?string $resourceType,
        private readonly ?string $resourceId,
        private readonly ?string $operation,
    ) {
        parent::__construct('Sync checkpoint is stale; perform a full resource resync.');
    }

    public static function forCheckpoint(
        int $afterCheckpoint,
        int $oldestAvailableCheckpoint,
        ?string $domain = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $operation = null,
    ): self {
        return new self($afterCheckpoint, $oldestAvailableCheckpoint, $domain, $resourceType, $resourceId, $operation);
    }

    public function reason(): string
    {
        return self::REASON;
    }

    public function requiredAction(): string
    {
        return self::REQUIRED_ACTION;
    }

    public function afterCheckpoint(): int
    {
        return $this->afterCheckpoint;
    }

    public function oldestAvailableCheckpoint(): int
    {
        return $this->oldestAvailableCheckpoint;
    }

    public function domain(): ?string
    {
        return $this->domain;
    }

    public function resourceType(): ?string
    {
        return $this->resourceType;
    }

    public function resourceId(): ?string
    {
        return $this->resourceId;
    }

    public function operation(): ?string
    {
        return $this->operation;
    }
}
