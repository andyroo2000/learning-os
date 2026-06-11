<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardReviewEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_review_events_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('card_review_events', [
            'id',
            'card_id',
            'import_job_id',
            'source_kind',
            'source_review_id',
            'source_card_id',
            'source_ease',
            'source_interval',
            'source_last_interval',
            'source_factor',
            'source_time_ms',
            'source_review_type',
            'raw_payload_json',
            'rating',
            'reviewed_at',
            'duration_ms',
            'client_event_id',
            'device_id',
            'client_created_at',
            'card_state_before',
            'scheduler_state_before',
            'scheduler_state_after',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(
            Schema::hasColumn('card_review_events', 'deleted_at'),
            'Review events are hard-deleted; export manifest counts intentionally have no review-event deleted_at filter.',
        );
    }

    public function test_review_event_can_be_created_with_a_card(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = now();

        $event = CardReviewEvent::factory()->create([
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $reviewedAt,
        ]);

        $this->assertIsString($event->id);
        $this->assertTrue(Str::isUlid($event->id));
        $this->assertSame(CardReviewRating::Good, $event->rating);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $event->id,
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $reviewedAt->toDateTimeString(),
            'duration_ms' => null,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);
    }

    public function test_import_source_fields_are_casts_but_not_mass_assignable(): void
    {
        $event = new CardReviewEvent([
            'card_id' => strtolower((string) Str::ulid()),
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now(),
            'import_job_id' => strtolower((string) Str::ulid()),
            'source_kind' => 'anki_import',
            'source_review_id' => 1700000000001,
            'source_card_id' => 1700000000002,
            'source_ease' => 3,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => 980,
            'source_review_type' => 1,
            'raw_payload_json' => ['ease' => 3],
        ]);

        $this->assertNull($event->import_job_id);
        $this->assertNull($event->source_kind);
        $this->assertNull($event->source_review_id);
        $this->assertNull($event->source_card_id);
        $this->assertNull($event->source_ease);
        $this->assertNull($event->source_interval);
        $this->assertNull($event->source_last_interval);
        $this->assertNull($event->source_factor);
        $this->assertNull($event->source_time_ms);
        $this->assertNull($event->source_review_type);
        $this->assertNull($event->raw_payload_json);

        $event->source_review_id = '1700000000001';
        $event->source_card_id = '1700000000002';
        $event->source_ease = '3';
        $event->source_interval = '12';
        $event->source_last_interval = '6';
        $event->source_factor = '2500';
        $event->source_time_ms = '980';
        $event->source_review_type = '1';
        $event->raw_payload_json = ['ease' => 3];

        $this->assertSame(1700000000001, $event->source_review_id);
        $this->assertSame(1700000000002, $event->source_card_id);
        $this->assertSame(3, $event->source_ease);
        $this->assertSame(12, $event->source_interval);
        $this->assertSame(6, $event->source_last_interval);
        $this->assertSame(2500, $event->source_factor);
        $this->assertSame(980, $event->source_time_ms);
        $this->assertSame(1, $event->source_review_type);
        $this->assertSame(['ease' => 3], $event->raw_payload_json);
    }

    public function test_review_event_belongs_to_a_card(): void
    {
        $card = Card::factory()->create();
        $event = CardReviewEvent::factory()->create(['card_id' => $card->id]);

        $this->assertTrue($event->card->is($card));
        $this->assertTrue($card->reviewEvents->contains($event));
    }

    public function test_review_events_are_kept_when_their_card_is_soft_deleted(): void
    {
        $card = Card::factory()->create();
        $event = CardReviewEvent::factory()->create(['card_id' => $card->id]);

        $card->delete();

        $this->assertDatabaseHas('card_review_events', [
            'id' => $event->id,
        ]);
    }

    public function test_review_events_are_deleted_when_their_card_is_force_deleted(): void
    {
        $card = Card::factory()->create();
        $event = CardReviewEvent::factory()->create(['card_id' => $card->id]);

        $card->forceDelete();

        $this->assertDatabaseMissing('card_review_events', [
            'id' => $event->id,
        ]);
    }

    public function test_client_event_and_device_pair_must_be_unique(): void
    {
        $card = Card::factory()->create();

        CardReviewEvent::factory()->create([
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);

        $this->expectException(QueryException::class);

        CardReviewEvent::factory()->create([
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);
    }
}
