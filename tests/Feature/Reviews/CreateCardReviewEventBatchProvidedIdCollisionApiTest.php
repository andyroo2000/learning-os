<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchProvidedIdCollisionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_same_user_provided_ulid_collisions_with_different_sync_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_claiming_same_user_event_without_sync_metadata_by_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_other_user_event_without_sync_metadata_by_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }
}
