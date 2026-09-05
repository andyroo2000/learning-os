<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Str;

class CreateCardReviewEventProvidedUlidRetryApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_returns_existing_event_for_client_provided_ulid_retries(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => strtoupper($id),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);
        $secondResponse = $this->postJson('/api/card-review-events', $payload);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Easy->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }
}
