<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reviews_a_legacy_imported_card_with_an_uppercase_ulid_in_a_batch(): void
    {
        $user = $this->signIn();
        $storedCardId = strtoupper((string) Str::ulid());
        Card::factory()->for($this->deckFor($user))->create(['id' => $storedCardId]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [[
                'card_id' => strtolower($storedCardId),
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
                'client_event_id' => 'legacy-event-123',
                'device_id' => 'device-abc',
                'client_created_at' => '2026-05-27T09:14:00Z',
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.card_id', $storedCardId);

        $this->assertSame(
            $storedCardId,
            CardReviewEvent::query()->findOrFail($response->json('data.0.id'))->card_id,
        );
    }

    public function test_it_stores_batch_review_timestamps_with_explicit_offsets_as_utc(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T05:15:00-04:00',
                    'client_event_id' => 'event-offset-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T05:14:00-04:00',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.0.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.0.id'),
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_normalizes_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'id' => '  '.strtoupper($id).'  ',
                        'card_id' => '  '.strtoupper($card->id).'  ',
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);
    }
}
