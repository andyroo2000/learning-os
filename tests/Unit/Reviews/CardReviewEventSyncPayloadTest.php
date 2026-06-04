<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CardReviewEventSyncPayloadTest extends TestCase
{
    public function test_it_uses_client_facing_review_event_keys(): void
    {
        $reviewEvent = new CardReviewEvent;
        $reviewEvent->setRawAttributes([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'card_deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'card_course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'rating' => 'good',
            'reviewed_at' => Carbon::parse('2026-05-30T12:14:00Z'),
            'client_event_id' => 'review-event-1',
            'device_id' => 'ios-device-1',
            'client_created_at' => Carbon::parse('2026-05-30T12:13:00Z'),
            'created_at' => Carbon::parse('2026-05-30T12:14:30Z'),
            'updated_at' => Carbon::parse('2026-05-30T12:15:00Z'),
        ], sync: true);

        $payload = CardReviewEventSyncPayload::fromReviewEvent($reviewEvent);

        $expected = [
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'rating' => 'good',
            'reviewed_at' => '2026-05-30T12:14:00.000000Z',
            'client_event_id' => 'review-event-1',
            'device_id' => 'ios-device-1',
            'client_created_at' => '2026-05-30T12:13:00.000000Z',
            'created_at' => '2026-05-30T12:14:30.000000Z',
            'updated_at' => '2026-05-30T12:15:00.000000Z',
        ];

        $this->assertSame('reviews', CardReviewEventSyncPayload::DOMAIN);
        $this->assertSame('card_review_event', CardReviewEventSyncPayload::RESOURCE_TYPE);
        $this->assertSame($expected, $payload);
    }

    public function test_it_serializes_nullable_client_metadata_as_null(): void
    {
        $reviewEvent = new CardReviewEvent;
        $reviewEvent->setRawAttributes([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'card_deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'card_course_id' => null,
            'rating' => 'easy',
            'reviewed_at' => Carbon::parse('2026-05-30T12:14:00Z'),
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'created_at' => Carbon::parse('2026-05-30T12:14:30Z'),
            'updated_at' => Carbon::parse('2026-05-30T12:15:00Z'),
        ], sync: true);

        $payload = CardReviewEventSyncPayload::fromReviewEvent($reviewEvent);

        $this->assertSame([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => null,
            'rating' => 'easy',
            'reviewed_at' => '2026-05-30T12:14:00.000000Z',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'created_at' => '2026-05-30T12:14:30.000000Z',
            'updated_at' => '2026-05-30T12:15:00.000000Z',
        ], $payload);
    }
}
