<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListCardReviewEventsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_review_events_for_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = $this->cardFor($user);

        $firstEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Hard,
            'reviewed_at' => now()->subDay(),
            'client_event_id' => 'event-1',
            'device_id' => 'device-a',
            'client_created_at' => now()->subDay()->subMinute(),
        ]);
        $secondEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now(),
            'client_event_id' => 'event-2',
            'device_id' => 'device-a',
            'client_created_at' => now()->subMinute(),
        ]);
        $otherEvent = CardReviewEvent::factory()->for($otherCard)->create();

        $response = $this->getJson("/api/cards/{$card->id}/review-events");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondEvent->id)
            ->assertJsonPath('data.1.id', $firstEvent->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'card_id',
                        'rating',
                        'reviewed_at',
                        'client_event_id',
                        'device_id',
                        'client_created_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $firstEvent->id,
                'card_id' => $card->id,
                'rating' => CardReviewRating::Hard->value,
                'reviewed_at' => $firstEvent->reviewed_at->toJSON(),
                'client_event_id' => 'event-1',
                'device_id' => 'device-a',
                'client_created_at' => $firstEvent->client_created_at->toJSON(),
            ])
            ->assertJsonFragment([
                'id' => $secondEvent->id,
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => $secondEvent->reviewed_at->toJSON(),
                'client_event_id' => 'event-2',
                'device_id' => 'device-a',
                'client_created_at' => $secondEvent->client_created_at->toJSON(),
            ])
            ->assertJsonMissing([
                'id' => $otherEvent->id,
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_card_has_no_review_events(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $eventForAnotherCard = CardReviewEvent::factory()->for($this->cardFor($user))->create();

        $response = $this->getJson("/api/cards/{$card->id}/review-events");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ])
            ->assertJsonMissing([
                'id' => $eventForAnotherCard->id,
            ]);
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $sharedReviewedAt = now()->subDays(2);

        foreach (range(1, 49) as $index) {
            CardReviewEvent::factory()->for($card)->create([
                'rating' => CardReviewRating::Good,
                'reviewed_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pc',
            'rating' => CardReviewRating::Again,
            'reviewed_at' => $sharedReviewedAt,
        ]);
        $highTieEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pd',
            'rating' => CardReviewRating::Easy,
            'reviewed_at' => $sharedReviewedAt,
        ]);

        $firstPage = $this->getJson("/api/cards/{$card->id}/review-events");

        $firstPage
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('data.49.id', $highTieEvent->id)
            ->assertJsonPath('meta.per_page', 50);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/cards/{$card->id}/review-events?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieEvent->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $response = $this->getJson("/api/cards/{$otherCard->id}/review-events");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();
        $missingCardId = strtolower((string) Str::ulid());

        $response = $this->getJson("/api/cards/{$missingCardId}/review-events");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $missingCardId = strtolower((string) Str::ulid());

        $response = $this->getJson("/api/cards/{$missingCardId}/review-events");

        $response->assertUnauthorized();
    }
}
