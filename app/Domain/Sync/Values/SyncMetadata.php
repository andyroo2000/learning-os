<?php

namespace App\Domain\Sync\Values;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class SyncMetadata
{
    private function __construct(
        public string $clientEventId,
        public string $deviceId,
        public Carbon $clientCreatedAt,
    ) {}

    public static function fromRequired(
        ?string $clientEventId,
        ?string $deviceId,
        ?Carbon $clientCreatedAt,
        string $message = 'Client event ID, device ID, and client created at are required.',
    ): self {
        if ($clientEventId === null || $deviceId === null || $clientCreatedAt === null) {
            throw new InvalidArgumentException($message);
        }

        return new self(
            clientEventId: $clientEventId,
            deviceId: $deviceId,
            clientCreatedAt: $clientCreatedAt,
        );
    }

    public static function fromNullable(
        ?string $clientEventId,
        ?string $deviceId,
        ?Carbon $clientCreatedAt,
    ): ?self {
        $hasAny = $clientEventId !== null
            || $deviceId !== null
            || $clientCreatedAt !== null;

        if (! $hasAny) {
            return null;
        }

        if ($clientEventId === null || $deviceId === null || $clientCreatedAt === null) {
            throw new InvalidArgumentException('Client event ID, device ID, and client created at must be provided together.');
        }

        return new self(
            clientEventId: $clientEventId,
            deviceId: $deviceId,
            clientCreatedAt: $clientCreatedAt,
        );
    }

    /**
     * @throws \JsonException when IDs contain invalid UTF-8.
     */
    public function lookupKey(): string
    {
        return ClientEventKey::fromParts($this->deviceId, $this->clientEventId)->toLookupKey();
    }
}
