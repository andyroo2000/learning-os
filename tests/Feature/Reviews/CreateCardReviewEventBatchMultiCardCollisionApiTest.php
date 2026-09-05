<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchMultiCardCollisionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hides_provided_ulid_collisions_in_multi_card_batches(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $conflictingId = strtolower((string) Str::ulid());
        $newId = strtolower((string) Str::ulid());
        $otherCard = Card::factory()->create();

        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $conflictingId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'other-event',
            'device_id' => 'other-device',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $conflictingId,
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'id' => $newId,
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseMissing('card_review_events', ['id' => $newId]);
    }
}
