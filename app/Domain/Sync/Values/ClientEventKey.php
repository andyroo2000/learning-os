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
     * @throws JsonException
     */
    public function toLookupKey(): string
    {
        return json_encode(
            [$this->deviceId, $this->clientEventId],
            JSON_THROW_ON_ERROR,
        );
    }
}
