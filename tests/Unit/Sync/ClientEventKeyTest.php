<?php

namespace Tests\Unit\Sync;

use App\Domain\Sync\Values\ClientEventKey;
use PHPUnit\Framework\TestCase;

class ClientEventKeyTest extends TestCase
{
    public function test_it_creates_a_stable_lookup_key_from_device_and_client_event_ids(): void
    {
        $key = ClientEventKey::fromParts(
            deviceId: 'device-abc',
            clientEventId: 'event-123',
        );

        $this->assertSame('["device-abc","event-123"]', $key->toLookupKey());
    }

    public function test_it_keeps_device_and_client_event_boundaries_distinct(): void
    {
        $firstKey = ClientEventKey::fromParts(
            deviceId: 'ab',
            clientEventId: 'c',
        );

        $secondKey = ClientEventKey::fromParts(
            deviceId: 'a',
            clientEventId: 'bc',
        );

        $this->assertNotSame($firstKey->toLookupKey(), $secondKey->toLookupKey());
    }
}
