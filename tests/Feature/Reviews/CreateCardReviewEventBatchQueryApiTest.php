<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateCardReviewEventBatchQueryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_bulk_lookup_queries_for_many_events(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $cards = Card::factory()->count(10)->for($deck)->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->postJson('/api/card-review-events/batch', [
                'events' => $cards
                    ->values()
                    ->map(fn (Card $card, int $index): array => [
                        'card_id' => $card->id,
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => "event-{$index}",
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ])
                    ->all(),
            ]);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response
            ->assertCreated()
            ->assertJsonCount(10, 'data');

        $selectQueries = $queries->filter(
            fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'),
        );

        // Auth, ownership, progression-owner discovery, card lookup, idempotency, and
        // latest chronology stay bounded for large batches.
        $this->assertLessThanOrEqual(6, $selectQueries->count());
        $this->assertDatabaseCount('card_review_events', 10);
    }
}
