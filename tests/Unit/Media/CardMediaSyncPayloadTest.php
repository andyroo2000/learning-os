<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Sync\CardMediaSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CardMediaSyncPayloadTest extends TestCase
{
    public function test_it_uses_client_facing_pivot_manifest_keys(): void
    {
        // Deliberately cover both timestamp branches in one payload: Carbon input and parseable string input.
        $payload = CardMediaSyncPayload::fromPivot(
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
            createdAt: Carbon::parse('2026-05-29T11:14:00Z'),
            updatedAt: '2026-05-29T11:15:00Z',
        );

        $expected = [
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'media_asset_id' => '01jzq4rqm0psp2zk6426fx85m9',
            'created_at' => '2026-05-29T11:14:00.000000Z',
            'updated_at' => '2026-05-29T11:15:00.000000Z',
        ];

        $this->assertSame('media', CardMediaSyncPayload::DOMAIN);
        $this->assertSame('card_media', CardMediaSyncPayload::RESOURCE_TYPE);
        $this->assertSame('01jzq4nny5xbnzw14q1g68b2yt:01jzq4rqm0psp2zk6426fx85m9', CardMediaSyncPayload::resourceId(
            '01jzq4nny5xbnzw14q1g68b2yt',
            '01jzq4rqm0psp2zk6426fx85m9',
        ));
        $this->assertSame($expected, $payload);
        $this->assertSame([
            'card_id',
            'media_asset_id',
            'created_at',
            'updated_at',
        ], array_keys($payload));
    }

    public function test_it_serializes_missing_pivot_timestamps_as_null(): void
    {
        $payload = CardMediaSyncPayload::fromPivot(
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
        );

        $this->assertSame([
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'media_asset_id' => '01jzq4rqm0psp2zk6426fx85m9',
            'created_at' => null,
            'updated_at' => null,
        ], $payload);
    }
}
