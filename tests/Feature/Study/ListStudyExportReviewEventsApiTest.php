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
            'import_job_id' => strtolower((string) str()->ulid()),
            'source_kind' => 'anki_import',
            'source_review_id' => 901,
            'source_card_id' => 701,
            'source_ease' => 2,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => 1200,
            'source_review_type' => 1,
            'raw_payload_json' => [
                'source_review_id' => 901,
                'source_card_id' => 701,
            ],
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
            ->assertJsonPath('data.0.import_job_id', $firstEvent->import_job_id)
            ->assertJsonPath('data.0.source_kind', 'anki_import')
            ->assertJsonPath('data.0.source_review_id', 901)
            ->assertJsonPath('data.0.source_card_id', 701)
            ->assertJsonPath('data.0.source_ease', 2)
            ->assertJsonPath('data.0.source_interval', 12)
            ->assertJsonPath('data.0.source_last_interval', 6)
            ->assertJsonPath('data.0.source_factor', 2500)
            ->assertJsonPath('data.0.source_time_ms', 1200)
            ->assertJsonPath('data.0.source_review_type', 1)
            ->assertJsonMissingPath('data.0.raw_payload_json')
            ->assertJsonPath('data.0.rating', CardReviewRating::Hard->value)
            ->assertJsonPath('data.0.reviewed_at', $firstEvent->reviewed_at->toJSON())
            ->assertJsonPath('data.0.duration_ms', 1200)
            ->assertJsonPath('data.0.client_event_id', 'event-1')
            ->assertJsonPath('data.0.device_id', 'device-a')
            ->assertJsonPath('data.0.client_created_at', $firstEvent->client_created_at->toJSON())
            ->assertJsonPath('data.1.id', $secondEvent->id)
            ->assertJsonPath('data.1.import_job_id', null)
            ->assertJsonPath('data.1.source_kind', null)
            ->assertJsonPath('data.1.source_review_id', null)
            ->assertJsonPath('data.1.source_card_id', null)
            ->assertJsonPath('data.1.source_ease', null)
            ->assertJsonPath('data.1.source_interval', null)
            ->assertJsonPath('data.1.source_last_interval', null)
            ->assertJsonPath('data.1.source_factor', null)
            ->assertJsonPath('data.1.source_time_ms', null)
            ->assertJsonPath('data.1.source_review_type', null)
            ->assertJsonMissingPath('data.1.raw_payload_json')
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
                        'import_job_id',
                        'source_kind',
                        'source_review_id',
                        'source_card_id',
                        'source_ease',
                        'source_interval',
                        'source_last_interval',
                        'source_factor',
                        'source_time_ms',
                        'source_review_type',
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
