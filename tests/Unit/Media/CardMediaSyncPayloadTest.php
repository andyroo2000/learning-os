<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Sync\CardMediaSyncPayload;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CardMediaSyncPayloadTest extends TestCase
{
    public function test_it_uses_client_facing_pivot_manifest_keys(): void
    {
        // Deliberately cover both timestamp branches in one payload: Carbon input and parseable string input.
        $payload = CardMediaSyncPayload::fromPivot(
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
            deckId: '01jzq4szwqs0e6hd3m7x2s4ana',
            courseId: '01jzq4tpn3qt1zgs8c3x3tgz9h',
            createdAt: Carbon::parse('2026-05-29T11:14:00Z'),
            updatedAt: '2026-05-29T11:15:00Z',
        );

        $expected = [
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'media_asset_id' => '01jzq4rqm0psp2zk6426fx85m9',
            'deck_id' => '01jzq4szwqs0e6hd3m7x2s4ana',
            'course_id' => '01jzq4tpn3qt1zgs8c3x3tgz9h',
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
            'deck_id' => null,
            'course_id' => null,
            'created_at' => null,
            'updated_at' => null,
        ], $payload);
    }

    public function test_it_normalizes_database_timestamp_strings(): void
    {
        $payload = CardMediaSyncPayload::fromPivot(
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
            createdAt: '2026-05-29 11:14:00',
            updatedAt: '2026-05-29 11:15:00',
        );

        $this->assertSame('2026-05-29T11:14:00.000000Z', $payload['created_at']);
        $this->assertSame('2026-05-29T11:15:00.000000Z', $payload['updated_at']);
    }

    public function test_it_rejects_invalid_timestamp_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card media timestamp must be a valid server timestamp.');

        CardMediaSyncPayload::fromPivot(
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
            updatedAt: 'tomorrow',
        );
    }
}
