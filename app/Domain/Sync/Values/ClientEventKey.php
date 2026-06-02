<?php

namespace App\Domain\Sync\Values;

use JsonException;

final readonly class ClientEventKey
{
    private function __construct(
        public string $deviceId,
        public string $clientEventId,
    ) {}

    public static function fromParts(string $deviceId, string $clientEventId): self
    {
        return new self(
            deviceId: $deviceId,
            clientEventId: $clientEventId,
        );
    }

    /**
     * @throws JsonException when IDs contain invalid UTF-8.
     */
    public static function lookupKey(string $deviceId, string $clientEventId): string
    {
        return self::fromParts($deviceId, $clientEventId)->toLookupKey();
    }

    /**
     * @throws JsonException when IDs contain invalid UTF-8.
     */
    public function toLookupKey(): string
    {
        return json_encode(
            [$this->deviceId, $this->clientEventId],
            JSON_THROW_ON_ERROR,
        );
    }
}
