<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyReviewConflictApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_camel_case_inputs(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->postJson('/api/study/reviews', [
            'cardId' => 'not-a-ulid',
            'grade' => 'good',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardId']);

        $this->postJson('/api/study/reviews', [
            'cardId' => [strtolower((string) str()->ulid())],
            'grade' => 'good',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardId']);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'perfect',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade']);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            'durationMs' => 86_400_001,
            'timeZone' => 'not-a-zone',
            'courseId' => 'not-a-ulid',
            'deck_id' => ['not-a-ulid'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['durationMs', 'timeZone', 'courseId', 'deck_id']);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            'courseId' => strtolower((string) str()->ulid()),
            'course_id' => strtolower((string) str()->ulid()),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);
    }

    public function test_it_returns_not_found_for_missing_or_unowned_cards(): void
    {
        $user = $this->signIn();
        $otherUserCard = $this->cardFor(User::factory()->create(), [
            'convolab_id' => '98f42a62-8303-410e-ad4d-5a69c55911bb',
        ]);
        $deletedOwnedCard = $this->cardFor($user);
        $cardInDeletedOwnedDeck = $this->cardFor($user);
        $deletedOwnedCard->delete();
        $cardInDeletedOwnedDeck->deck()->firstOrFail()->delete();

        $this->postJson('/api/study/reviews', [
            'cardId' => strtolower((string) str()->ulid()),
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => $otherUserCard->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => strtoupper((string) $otherUserCard->convolab_id),
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => $deletedOwnedCard->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => $cardInDeletedOwnedDeck->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_returns_retryable_response_when_review_event_race_recovery_fails(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->app->instance(ReviewCardAction::class, new class extends ReviewCardAction
        {
            public function __construct() {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::retryableConflict();
            }
        });

        $response = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ]);

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('message', 'Card review event ID conflict could not be resolved; retry the request.')
            ->assertJsonPath('reason', 'card_review_event_retry');
    }

    public function test_it_returns_review_log_id_when_card_disappears_after_review_is_recorded(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $card = Card::factory()->for($deck)->create();
        $otherDeck = $this->deckFor($user);
        Card::factory()->for($otherDeck)->create();

        $realReviewCard = app(ReviewCardAction::class);

        $this->app->instance(ReviewCardAction::class, new class($realReviewCard) extends ReviewCardAction
        {
            public function __construct(private readonly ReviewCardAction $realReviewCard) {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                $result = $this->realReviewCard->handle($data);

                Card::query()->whereKey($data->cardId)->firstOrFail()->delete();

                return $result;
            }
        });

        $response = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            'courseId' => strtoupper($course->id),
            'deck_id' => strtoupper($deck->id),
        ]);

        $reviewLogId = $response->json('reviewLogId');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Study card not found after review.')
            ->assertJsonPath('committed', true)
            ->assertJsonPath('cardFetchFailed', true)
            ->assertJsonPath('card', null)
            ->assertJsonPath('overview.newCount', 0)
            ->assertJsonPath('overview.reviewCount', 0)
            ->assertJsonPath('overview.totalCards', 0);

        $this->assertArrayHasKey('card', $response->json());

        $this->assertIsString($reviewLogId);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewLogId,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_returns_conflict_for_owned_review_event_conflicts(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->app->instance(ReviewCardAction::class, new class($user->id) extends ReviewCardAction
        {
            public function __construct(private readonly int $conflictingUserId) {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::conflict($this->conflictingUserId);
            }
        });

        $response = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');
    }

    public function test_it_hides_cross_user_review_event_conflicts(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $card = $this->cardFor($user);

        $this->app->instance(ReviewCardAction::class, new class($otherUser->id) extends ReviewCardAction
        {
            public function __construct(private readonly int $conflictingUserId) {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::conflict($this->conflictingUserId);
            }
        });

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');
    }
}
