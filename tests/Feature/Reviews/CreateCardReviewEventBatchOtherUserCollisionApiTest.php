<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchOtherUserCollisionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hides_sync_metadata_collisions_for_other_users(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $card->update(['last_reviewed_at' => '2026-05-28T09:15:00Z']);

        CardReviewEvent::factory()->for($otherCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_provided_ulid_collisions_for_other_users(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'other-event',
            'device_id' => 'other-device',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }
}
