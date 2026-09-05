<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreateCardReviewEventBatchRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_an_empty_batch(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_returns_a_batch_level_validation_error_when_the_action_rejects_the_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->mock(ReviewCardBatchAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new InvalidArgumentException('Batch invariant failed.'));

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

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events'])
            ->assertJsonPath('errors.events.0', 'Batch invariant failed.');

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

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

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
