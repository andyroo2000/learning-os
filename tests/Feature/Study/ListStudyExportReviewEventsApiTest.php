<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListStudyExportReviewEventsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/review-events')->assertUnauthorized();
    }

    public function test_convolab_review_logs_export_alias_requires_authentication(): void
    {
        $this->getJson('/api/study/export/review-logs')->assertUnauthorized();
    }

    public function test_index_returns_current_review_events_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);
        $deletedDeck = $this->deckFor($user);
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create();
        $otherCard = $this->cardFor(User::factory()->create());

        $firstEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Hard,
            'reviewed_at' => now()->subHour(),
            'duration_ms' => 1200,
            'client_event_id' => 'event-1',
            'device_id' => 'device-a',
            'client_created_at' => now()->subHour()->subMinute(),
        ]);
        $secondEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now(),
            'client_event_id' => 'event-2',
            'device_id' => 'device-a',
            'client_created_at' => now()->subMinute(),
        ]);
        $deletedCardEvent = CardReviewEvent::factory()->for($deletedCard)->create();
        $deletedDeckEvent = CardReviewEvent::factory()->for($cardInDeletedDeck)->create();
        $otherEvent = CardReviewEvent::factory()->for($otherCard)->create();

        $deletedCard->delete();
        DB::table('decks')
            ->where('id', $deletedDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this->getJson('/api/study/export/review-events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstEvent->id)
            ->assertJsonPath('data.0.card_id', $card->id)
            ->assertJsonPath('data.0.deck_id', $card->deck_id)
            ->assertJsonPath('data.0.course_id', $card->deckCourseId())
            ->assertJsonPath('data.0.rating', CardReviewRating::Hard->value)
            ->assertJsonPath('data.0.reviewed_at', $firstEvent->reviewed_at->toJSON())
            ->assertJsonPath('data.0.duration_ms', 1200)
            ->assertJsonPath('data.0.client_event_id', 'event-1')
            ->assertJsonPath('data.0.device_id', 'device-a')
            ->assertJsonPath('data.0.client_created_at', $firstEvent->client_created_at->toJSON())
            ->assertJsonPath('data.1.id', $secondEvent->id)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value)
            ->assertJsonMissing([
                'id' => $deletedCardEvent->id,
            ])
            ->assertJsonMissing([
                'id' => $deletedDeckEvent->id,
            ])
            ->assertJsonMissing([
                'id' => $otherEvent->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'card_id',
                        'deck_id',
                        'course_id',
                        'rating',
                        'reviewed_at',
                        'duration_ms',
                        'client_event_id',
                        'device_id',
                        'client_created_at',
                        'card_state_before',
                        'scheduler_state_before',
                        'scheduler_state_after',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_convolab_review_logs_export_alias_returns_review_events_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $event = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Good,
        ]);
        CardReviewEvent::factory()->for($this->cardFor(User::factory()->create()))->create();

        $this->getJson('/api/study/export/review-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.card_id', $card->id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value);
    }
}
