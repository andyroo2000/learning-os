<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardReviewEventBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_batch_containing_a_progression_locked_card(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $this->postJson('/api/card-review-events/batch', [
            'events' => [[
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
                'client_event_id' => 'event-locked',
                'device_id' => 'device-abc',
                'client_created_at' => '2026-05-27T09:14:00Z',
            ]],
        ])
            ->assertConflict()
            ->assertJsonPath('reason', 'card_progression_locked');

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_an_actionable_conflict_and_writes_nothing_when_any_new_batch_event_is_out_of_order(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);

        $this->postJson('/api/card-review-events/batch', [
            'events' => [[
                'card_id' => $firstCard->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => '2026-05-27T09:20:00Z',
                'client_event_id' => 'existing-event',
                'device_id' => 'device-abc',
                'client_created_at' => '2026-05-27T09:19:00Z',
            ]],
        ])->assertCreated();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Again->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'late-event',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:25:00Z',
                    'client_event_id' => 'otherwise-valid-event',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:24:00Z',
                ],
            ],
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Review events must be submitted after the card\'s latest review, ordered by reviewed_at and id.',
            )
            ->assertJsonPath('reason', 'card_review_event_out_of_order');

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseMissing('card_review_events', ['client_event_id' => 'late-event']);
        $this->assertDatabaseMissing('card_review_events', ['client_event_id' => 'otherwise-valid-event']);
        $this->assertDatabaseCount('sync_feed_entries', 2);
        $this->assertNull($secondCard->refresh()->last_reviewed_at);
    }
}
