<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CreateCardReviewEventBatchCreationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_card_review_events_in_a_batch(): void
    {
        [$course, $firstDeck, $secondDeck, $firstCard, $secondCard] = $this->batchCards();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'duration_ms' => '1250',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'duration_ms' => 1820,
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.card_id', $firstCard->id)
            ->assertJsonPath('data.0.deck_id', $firstDeck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.duration_ms', 1250)
            ->assertJsonPath('data.0.client_event_id', 'event-123')
            ->assertJsonPath('data.0.card_state_before.study_status', 'new')
            ->assertJsonPath('data.0.scheduler_state_before', null)
            ->assertJsonPath('data.0.scheduler_state_after.reps', 1)
            ->assertJsonPath('data.1.card_id', $secondCard->id)
            ->assertJsonPath('data.1.deck_id', $secondDeck->id)
            ->assertJsonPath('data.1.course_id', $course->id)
            ->assertJsonPath('data.1.rating', CardReviewRating::Easy->value)
            ->assertJsonPath('data.1.duration_ms', 1820)
            ->assertJsonPath('data.1.client_event_id', 'event-456')
            ->assertJsonPath('data.1.card_state_before.study_status', 'new')
            ->assertJsonPath('data.1.scheduler_state_before', null)
            ->assertJsonPath('data.1.scheduler_state_after.scheduled_days', 8)
            ->assertJsonStructure([
                'data' => [
                    [
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

        $this->assertReviewEventsPersisted($response, $firstCard, $secondCard);
    }

    /**
     * @return array{Course, Deck, Deck, Card, Card}
     */
    private function batchCards(): array
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $firstDeck = Deck::factory()->for($course)->for($user)->create();
        $secondDeck = Deck::factory()->for($course)->for($user)->create();

        return [
            $course,
            $firstDeck,
            $secondDeck,
            Card::factory()->for($firstDeck)->create(),
            Card::factory()->for($secondDeck)->create(),
        ];
    }

    private function assertReviewEventsPersisted(
        TestResponse $response,
        Card $firstCard,
        Card $secondCard,
    ): void {
        $this->assertTrue(Str::isUlid($response->json('data.0.id')));
        $this->assertTrue(Str::isUlid($response->json('data.1.id')));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.0.id'),
            'card_id' => $firstCard->id,
            'rating' => CardReviewRating::Good->value,
            'duration_ms' => 1250,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.1.id'),
            'card_id' => $secondCard->id,
            'rating' => CardReviewRating::Easy->value,
            'duration_ms' => 1820,
            'client_event_id' => 'event-456',
            'device_id' => 'device-abc',
        ]);
    }
}
