<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_card_review_event(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'card_id',
                    'rating',
                    'reviewed_at',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Easy->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => "  {$card->id}  ",
            'rating' => '  hard  ',
            'reviewed_at' => '  2026-05-27T09:15:00Z  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.rating', CardReviewRating::Hard->value);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $response = $this->postJson('/api/card-review-events', [
            'id' => 'not-a-ulid',
            'card_id' => 'also-not-a-ulid',
            'rating' => 'medium',
            'reviewed_at' => 'not-a-date',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'card_id', 'rating', 'reviewed_at']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_missing_card(): void
    {
        $response = $this->postJson('/api/card-review-events', [
            'card_id' => strtolower((string) Str::ulid()),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
