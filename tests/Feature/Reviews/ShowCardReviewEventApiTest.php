<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowCardReviewEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_an_owned_review_event(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Hard,
            'reviewed_at' => now()->subMinute()->startOfSecond(),
            'duration_ms' => 980,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => now()->subMinutes(2)->startOfSecond(),
            'created_at' => now()->subMinute()->startOfSecond(),
            'updated_at' => now()->startOfSecond(),
        ]);

        $response = $this->getJson("/api/card-review-events/{$reviewEvent->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $reviewEvent->id,
                    'card_id' => $reviewEvent->card_id,
                    'deck_id' => $deck->id,
                    'course_id' => $course->id,
                    'import_job_id' => null,
                    'source_kind' => null,
                    'source_review_id' => null,
                    'source_card_id' => null,
                    'source_ease' => null,
                    'source_interval' => null,
                    'source_last_interval' => null,
                    'source_factor' => null,
                    'source_time_ms' => null,
                    'source_review_type' => null,
                    'rating' => CardReviewRating::Hard->value,
                    'reviewed_at' => $reviewEvent->reviewed_at->toJSON(),
                    'duration_ms' => 980,
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => $reviewEvent->client_created_at->toJSON(),
                    'card_state_before' => null,
                    'scheduler_state_before' => null,
                    'scheduler_state_after' => null,
                    'created_at' => $reviewEvent->created_at->toJSON(),
                    'updated_at' => $reviewEvent->updated_at->toJSON(),
                ],
            ]);
    }

    public function test_it_shows_an_owned_review_event_with_an_uppercase_route_id(): void
    {
        $user = $this->signIn();
        $reviewEvent = $this->cardReviewEventFor($user);

        $response = $this->getJson('/api/card-review-events/'.strtoupper($reviewEvent->id));

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $reviewEvent->id);
    }

    public function test_it_hides_another_users_review_event(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $reviewEvent = $this->cardReviewEventFor($otherUser);

        $response = $this->getJson("/api/card-review-events/{$reviewEvent->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_review_event(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events/'.(string) Str::ulid());

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_review_event_id(): void
    {
        $this->signIn();

        // The route ULID constraint rejects this before model binding.
        $response = $this->getJson('/api/card-review-events/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_review_event_on_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $reviewEvent = $this->cardReviewEventFor($user);

        $reviewEvent->card->delete();

        $response = $this->getJson("/api/card-review-events/{$reviewEvent->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_review_event_on_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $reviewEvent = $this->cardReviewEventFor($user);

        // The policy's deck lookup excludes soft-deleted decks.
        $reviewEvent->card->deck->delete();

        $response = $this->getJson("/api/card-review-events/{$reviewEvent->id}");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $reviewEvent = $this->cardReviewEventFor(User::factory()->create());

        $response = $this->getJson("/api/card-review-events/{$reviewEvent->id}");

        $response->assertUnauthorized();
    }
}
