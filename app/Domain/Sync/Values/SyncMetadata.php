<?php

namespace App\Domain\Sync\Values;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class SyncMetadata
{
    // Review sync metadata uses default string columns; enforce the limit before SQLite can mask it.
    public const MAX_CLIENT_EVENT_ID_LENGTH = 255;

    public const MAX_DEVICE_ID_LENGTH = 255;

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
            clientEventId: self::validateClientEventId($clientEventId),
            deviceId: self::validateDeviceId($deviceId),
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
            clientEventId: self::validateClientEventId($clientEventId),
            deviceId: self::validateDeviceId($deviceId),
            clientCreatedAt: $clientCreatedAt,
        );
    }

    private static function validateClientEventId(string $clientEventId): string
    {
        if (mb_strlen($clientEventId) > self::MAX_CLIENT_EVENT_ID_LENGTH) {
            throw new InvalidArgumentException('Client event ID must not exceed '.self::MAX_CLIENT_EVENT_ID_LENGTH.' characters.');
        }

        return $clientEventId;
    }

    private static function validateDeviceId(string $deviceId): string
    {
        if (mb_strlen($deviceId) > self::MAX_DEVICE_ID_LENGTH) {
            throw new InvalidArgumentException('Device ID must not exceed '.self::MAX_DEVICE_ID_LENGTH.' characters.');
        }

        return $deviceId;
    }

    /**
     * @throws \JsonException when IDs contain invalid UTF-8.
     */
    public function lookupKey(): string
    {
        return ClientEventKey::fromParts($this->deviceId, $this->clientEventId)->toLookupKey();
    }
}
