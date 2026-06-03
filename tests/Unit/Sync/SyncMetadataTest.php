<?php

namespace Tests\Unit\Sync;

use App\Domain\Sync\Values\SyncMetadata;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SyncMetadataTest extends TestCase
{
    public function test_it_returns_null_when_all_sync_metadata_is_absent(): void
    {
        $this->assertNull(SyncMetadata::fromNullable(null, null, null));
    }

    public function test_it_requires_sync_metadata_fields_together(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client event ID, device ID, and client created at must be provided together.');

        SyncMetadata::fromNullable(
            clientEventId: 'event-123',
            deviceId: null,
            clientCreatedAt: Carbon::parse('2026-05-27T09:14:00Z'),
        );
    }

    public function test_it_requires_metadata_for_required_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync metadata is required.');

        SyncMetadata::fromRequired(
            clientEventId: null,
            deviceId: null,
            clientCreatedAt: null,
            message: 'Sync metadata is required.',
        );
    }

    public function test_required_construction_uses_the_custom_message_for_partial_metadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch sync metadata is required.');

        SyncMetadata::fromRequired(
            clientEventId: 'event-123',
            deviceId: null,
            clientCreatedAt: Carbon::parse('2026-05-27T09:14:00Z'),
            message: 'Batch sync metadata is required.',
        );
    }

    public function test_it_exposes_metadata_values(): void
    {
        $metadata = SyncMetadata::fromRequired(
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: Carbon::parse('2026-05-27T09:14:00Z'),
        );

        $this->assertSame('event-123', $metadata->clientEventId);
        $this->assertSame('device-abc', $metadata->deviceId);
        $this->assertTrue($metadata->clientCreatedAt->equalTo(Carbon::parse('2026-05-27T09:14:00Z')));
    }

    public function test_it_builds_a_lookup_key_from_metadata(): void
    {
        $metadata = SyncMetadata::fromRequired(
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: Carbon::parse('2026-05-27T09:14:00Z'),
        );

        $this->assertSame('["device-abc","event-123"]', $metadata->lookupKey());
    }
}
