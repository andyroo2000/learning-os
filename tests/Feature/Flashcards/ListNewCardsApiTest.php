<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListNewCardsApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/cards/new');

        $response->assertUnauthorized();
    }

    public function test_it_lists_queued_new_cards_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $unqueuedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'new_queue_position' => 3,
        ]);
        $otherUserCard = $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::New, [
            'new_queue_position' => 5,
        ]);
        $deletedDeck->delete();
        $deletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 4,
        ]);
        $deletedCard->delete();

        $response = $this->getJson('/api/cards/new');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstCard->id)
            ->assertJsonPath('data.0.study_status', 'new')
            ->assertJsonPath('data.0.new_queue_position', 1)
            ->assertJsonPath('data.1.id', $secondCard->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'deck_id',
                        'course_id',
                        'front_text',
                        'back_text',
                        'card_type',
                        'prompt_json',
                        'answer_json',
                        'search_text',
                        'study_status',
                        'new_queue_position',
                        'scheduler_state',
                        'due_at',
                        'introduced_at',
                        'failed_at',
                        'last_reviewed_at',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
            ])
            ->assertJsonMissing(['id' => $unqueuedCard->id])
            ->assertJsonMissing(['id' => $reviewCard->id])
            ->assertJsonMissing(['id' => $otherUserCard->id])
            ->assertJsonMissing(['id' => $deletedDeckCard->id])
            ->assertJsonMissing(['id' => $deletedCard->id]);
    }

    public function test_it_filters_new_cards_by_course_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $standaloneDeck = $this->deckFor($user);
        $courseCard = $this->cardWithStudyStatus($courseDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $standaloneCard = $this->cardWithStudyStatus($standaloneDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $response = $this->getJson("/api/cards/new?course_id={$course->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseCard->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing(['id' => $standaloneCard->id]);
    }

    public function test_it_filters_new_cards_by_deck_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        $deckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $otherDeckCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $otherUserCard = $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $response = $this->getJson("/api/cards/new?deck_id={$deck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckCard->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing(['id' => $otherDeckCard->id])
            ->assertJsonMissing(['id' => $otherUserCard->id]);
    }

    public function test_it_returns_empty_when_deck_id_and_course_id_are_in_different_courses(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        $otherCourseCard = $this->cardWithStudyStatus($otherCourseDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $response = $this->getJson("/api/cards/new?course_id={$course->id}&deck_id={$otherCourseDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['id' => $otherCourseCard->id]);
    }

    public function test_it_normalizes_course_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $courseCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/new?course_id=%20'.strtoupper($course->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseCard->id);
    }

    public function test_it_normalizes_deck_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $deckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/new?deck_id=%20'.strtoupper($deck->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckCard->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonPath('data.0.course_id', $course->id);
    }

    public function test_it_returns_an_empty_list_for_another_users_deck_id(): void
    {
        $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());
        $otherUserCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $response = $this->getJson("/api/cards/new?deck_id={$otherDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['id' => $otherUserCard->id]);
    }

    public function test_it_rejects_blank_course_id_filters_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/new?course_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_blank_deck_id_filters_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/new?deck_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_malformed_course_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/new?course_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/new?deck_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_array_course_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/new?course_id[]=01J00000000000000000000000');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_array_deck_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/new?deck_id[]=01J00000000000000000000000');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_preserves_filters_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $firstPage = $this->getJson("/api/cards/new?course_id={$course->id}&per_page=1");

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstCard->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'course_id', $course->id);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '1');

        $secondPage = $this->getJson($this->pathAndQueryFromUrl($nextUrl));

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondCard->id);
    }

    public function test_it_preserves_deck_id_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $otherDeckCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $firstPage = $this->getJson("/api/cards/new?deck_id={$deck->id}&per_page=1");

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstCard->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'deck_id', $deck->id);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '1');

        $secondPage = $this->getJson($this->pathAndQueryFromUrl($nextUrl));

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondCard->id)
            ->assertJsonMissing(['id' => $otherDeckCard->id]);
    }

    public function test_it_accepts_cursor_page_sizes(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()
            ->count(3)
            ->sequence(
                ['new_queue_position' => 1],
                ['new_queue_position' => 2],
                ['new_queue_position' => 3],
            )
            ->for($deck)
            ->create([
                'study_status' => CardStudyStatus::New,
            ]);

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/cards/new');
    }
}
