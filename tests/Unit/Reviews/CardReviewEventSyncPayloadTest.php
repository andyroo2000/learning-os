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
            'import_job_id' => '01k1j8n4st9y2aqj9b43r1dz0e',
            'source_kind' => 'anki_import',
            'source_review_id' => 901,
            'source_card_id' => 701,
            'source_ease' => 3,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => 1420,
            'source_review_type' => 1,
            'raw_payload_json' => json_encode([
                'source_review_id' => 901,
                'source_card_id' => 701,
            ]),
            'rating' => 'good',
            'reviewed_at' => Carbon::parse('2026-05-30T12:14:00Z'),
            'duration_ms' => 1420,
            'client_event_id' => 'review-event-1',
            'device_id' => 'ios-device-1',
            'client_created_at' => Carbon::parse('2026-05-30T12:13:00Z'),
            'card_state_before' => json_encode([
                'study_status' => 'learning',
                'new_queue_position' => 4,
            ]),
            'scheduler_state_before' => json_encode([
                'state' => 0,
                'reps' => 0,
            ]),
            'scheduler_state_after' => json_encode([
                'state' => 2,
                'reps' => 1,
            ]),
            'created_at' => Carbon::parse('2026-05-30T12:14:30Z'),
            'updated_at' => Carbon::parse('2026-05-30T12:15:00Z'),
        ], sync: true);

        $payload = CardReviewEventSyncPayload::fromReviewEvent($reviewEvent);

        $expected = [
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'import_job_id' => '01k1j8n4st9y2aqj9b43r1dz0e',
            'source_kind' => 'anki_import',
            'source_review_id' => 901,
            'source_card_id' => 701,
            'source_ease' => 3,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => 1420,
            'source_review_type' => 1,
            'rating' => 'good',
            'reviewed_at' => '2026-05-30T12:14:00.000000Z',
            'duration_ms' => 1420,
            'client_event_id' => 'review-event-1',
            'device_id' => 'ios-device-1',
            'client_created_at' => '2026-05-30T12:13:00.000000Z',
            'card_state_before' => [
                'study_status' => 'learning',
                'new_queue_position' => 4,
            ],
            'scheduler_state_before' => [
                'state' => 0,
                'reps' => 0,
            ],
            'scheduler_state_after' => [
                'state' => 2,
                'reps' => 1,
            ],
            'created_at' => '2026-05-30T12:14:30.000000Z',
            'updated_at' => '2026-05-30T12:15:00.000000Z',
            'deleted_at' => null,
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
            'import_job_id' => null,
            'source_kind' => null,
            'source_review_id' => null,
            'source_card_id' => null,
            'source_ease' => null,
            'source_interval' => null,
            'source_last_interval' => null,
            'source_factor' => null,
            'source_time_ms' => null,
            'source_review_type' => null,
            'rating' => 'easy',
            'reviewed_at' => Carbon::parse('2026-05-30T12:14:00Z'),
            'duration_ms' => null,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'card_state_before' => null,
            'scheduler_state_before' => null,
            'scheduler_state_after' => null,
            'created_at' => Carbon::parse('2026-05-30T12:14:30Z'),
            'updated_at' => Carbon::parse('2026-05-30T12:15:00Z'),
        ], sync: true);

        $payload = CardReviewEventSyncPayload::fromReviewEvent($reviewEvent);

        $this->assertSame([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => null,
            'import_job_id' => null,
            'source_kind' => null,
            'source_review_id' => null,
            'source_card_id' => null,
            'source_ease' => null,
            'source_interval' => null,
            'source_last_interval' => null,
            'source_factor' => null,
            'source_time_ms' => null,
            'source_review_type' => null,
            'rating' => 'easy',
            'reviewed_at' => '2026-05-30T12:14:00.000000Z',
            'duration_ms' => null,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'card_state_before' => null,
            'scheduler_state_before' => null,
            'scheduler_state_after' => null,
            'created_at' => '2026-05-30T12:14:30.000000Z',
            'updated_at' => '2026-05-30T12:15:00.000000Z',
            'deleted_at' => null,
        ], $payload);
    }

    public function test_it_uses_the_supplied_tombstone_timestamp_for_deleted_at(): void
    {
        $reviewEvent = new CardReviewEvent;
        $reviewEvent->setRawAttributes([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'card_deck_id' => null,
            'card_course_id' => null,
            'rating' => 'good',
            'reviewed_at' => null,
            'duration_ms' => null,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'card_state_before' => null,
            'scheduler_state_before' => null,
            'scheduler_state_after' => null,
            'created_at' => null,
            'updated_at' => Carbon::parse('2026-05-30T12:15:00Z'),
        ], sync: true);

        $payload = CardReviewEventSyncPayload::fromReviewEvent(
            $reviewEvent,
            Carbon::parse('2026-05-30T12:16:00Z'),
        );

        $this->assertSame('2026-05-30T12:16:00.000000Z', $payload['deleted_at']);
    }

    public function test_it_preserves_raw_legacy_rating_values(): void
    {
        $reviewEvent = new CardReviewEvent;
        $reviewEvent->setRawAttributes([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'card_deck_id' => null,
            'card_course_id' => null,
            'rating' => 'legacy-rating',
            'reviewed_at' => null,
            'duration_ms' => null,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'card_state_before' => null,
            'scheduler_state_before' => null,
            'scheduler_state_after' => null,
            'created_at' => null,
            'updated_at' => null,
        ], sync: true);

        $payload = CardReviewEventSyncPayload::fromReviewEvent($reviewEvent);

        $this->assertSame('legacy-rating', $payload['rating']);
    }
}
