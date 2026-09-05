<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Support\Str;

class CreateCardReviewEventApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_rejects_reviews_for_progression_locked_cards(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ])
            ->assertConflict()
            ->assertJsonPath('reason', 'card_progression_locked');

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_creates_a_card_review_event(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.duration_ms', null)
            ->assertJsonPath('data.card_state_before.study_status', 'new')
            ->assertJsonPath('data.card_state_before.due_at', null)
            ->assertJsonPath('data.scheduler_state_before', null)
            ->assertJsonPath('data.scheduler_state_after.reps', 1)
            ->assertJsonPath('data.scheduler_state_after.state', 1)
            ->assertJsonStructure([
                'data' => [
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
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
    }

    public function test_it_reviews_a_legacy_imported_card_with_an_uppercase_ulid(): void
    {
        $user = $this->signIn();
        $storedCardId = strtoupper((string) Str::ulid());
        Card::factory()->for($this->deckFor($user))->create(['id' => $storedCardId]);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => strtolower($storedCardId),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $storedCardId);

        $this->assertSame(
            $storedCardId,
            CardReviewEvent::query()->findOrFail($response->json('data.id'))->card_id,
        );
    }

    public function test_it_stores_client_sync_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'duration_ms' => '1250',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.duration_ms', 1250)
            ->assertJsonPath('data.client_event_id', 'event-123')
            ->assertJsonPath('data.device_id', 'device-abc')
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'duration_ms' => 1250,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_stores_review_timestamps_with_explicit_offsets_as_utc(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T05:15:00-04:00',
            'client_event_id' => 'event-offset-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T05:14:00-04:00',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }
}
