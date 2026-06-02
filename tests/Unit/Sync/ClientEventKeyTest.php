<?php

namespace Tests\Unit\Sync;

use App\Domain\Sync\Values\ClientEventKey;
use PHPUnit\Framework\TestCase;

class ClientEventKeyTest extends TestCase
{
    public function test_it_builds_a_stable_lookup_key_from_parts(): void
    {
        $this->assertSame(
            '["device-abc","event-123"]',
            ClientEventKey::lookupKey('device-abc', 'event-123'),
        );
    }

    public function test_value_object_representation_is_stable(): void
    {
        $key = ClientEventKey::fromParts('device-abc', 'event-123');

        $this->assertSame('["device-abc","event-123"]', $key->toLookupKey());
    }

    public function test_it_keeps_device_and_client_event_boundaries_distinct(): void
    {
        $this->assertNotSame(
            ClientEventKey::lookupKey('ab', 'c'),
            ClientEventKey::lookupKey('a', 'bc'),
        );
    }
}
