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
            'rating',
            'reviewed_at',
            'client_event_id',
            'device_id',
            'client_created_at',
            'created_at',
            'updated_at',
        ]));
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
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);
    }

    public function test_review_event_belongs_to_a_card(): void
    {
        $card = Card::factory()->create();
        $event = CardReviewEvent::factory()->create(['card_id' => $card->id]);

        $this->assertTrue($event->card->is($card));
        $this->assertTrue($card->reviewEvents->contains($event));
    }

    public function test_review_events_are_deleted_when_their_card_is_deleted(): void
    {
        $card = Card::factory()->create();
        $event = CardReviewEvent::factory()->create(['card_id' => $card->id]);

        $card->delete();

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
