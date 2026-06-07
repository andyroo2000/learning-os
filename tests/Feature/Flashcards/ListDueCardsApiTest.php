<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AssertsCursorPagination;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListDueCardsApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/cards/due');

        $response->assertUnauthorized();
    }

    public function test_it_lists_due_active_cards_for_the_authenticated_user(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeck->delete();
        $relearningCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => now()->subHours(2),
        ]);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);
        $futureCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->addMinute(),
        ]);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'due_at' => now()->subDay(),
        ]);
        $suspendedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended, [
            'due_at' => now()->subDay(),
        ]);
        $buriedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Buried, [
            'due_at' => now()->subDay(),
        ]);
        $otherUserCard = $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
            'due_at' => now()->subDay(),
        ]);
        $deletedDeckCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'due_at' => now()->subDay(),
        ]);
        $deletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subDay(),
        ]);
        $deletedCard->delete();

        $response = $this->getJson('/api/cards/due');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $relearningCard->id)
            ->assertJsonPath('data.0.study_status', 'relearning')
            ->assertJsonPath('data.1.id', $reviewCard->id)
            ->assertJsonMissing(['id' => $futureCard->id])
            ->assertJsonMissing(['id' => $newCard->id])
            ->assertJsonMissing(['id' => $suspendedCard->id])
            ->assertJsonMissing(['id' => $buriedCard->id])
            ->assertJsonMissing(['id' => $otherUserCard->id])
            ->assertJsonMissing(['id' => $deletedDeckCard->id])
            ->assertJsonMissing(['id' => $deletedCard->id]);
    }

    public function test_it_filters_due_cards_by_course_id(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $standaloneDeck = $this->deckFor($user);
        $courseCard = $this->cardWithStudyStatus($courseDeck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);
        $standaloneCard = $this->cardWithStudyStatus($standaloneDeck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/api/cards/due?course_id={$course->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseCard->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing(['id' => $standaloneCard->id]);
    }

    public function test_it_filters_due_cards_by_deck_id(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        $deckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);
        $otherDeckCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);
        $otherUserCard = $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/api/cards/due?deck_id={$deck->id}");

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
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        $otherCourseCard = $this->cardWithStudyStatus($otherCourseDeck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/api/cards/due?course_id={$course->id}&deck_id={$otherCourseDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['id' => $otherCourseCard->id]);
    }

    public function test_it_normalizes_course_id_filters_without_global_trim_middleware(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $courseCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/due?course_id=%20'.strtoupper($course->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseCard->id);
    }

    public function test_it_normalizes_deck_id_filters_without_global_trim_middleware(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $deckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/due?deck_id=%20'.strtoupper($deck->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckCard->id)
            ->assertJsonPath('data.0.deck_id', $deck->id);
    }

    public function test_it_returns_an_empty_list_for_another_users_deck_id(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());
        $otherUserCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/api/cards/due?deck_id={$otherDeck->id}");

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
            ->getJson('/api/cards/due?course_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_blank_deck_id_filters_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards/due?deck_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_malformed_course_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/due?course_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/due?deck_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_array_course_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/due?course_id[]=01J00000000000000000000000');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_array_deck_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/due?deck_id[]=01J00000000000000000000000');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_preserves_filters_when_following_a_cursor(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subHours(2),
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
            'due_at' => now()->subHour(),
        ]);

        $firstPage = $this->getJson("/api/cards/due?course_id={$course->id}&per_page=1");

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
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => now()->subHours(2),
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
            'due_at' => now()->subHour(),
        ]);
        $otherDeckCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => now()->subMinutes(90),
        ]);

        $firstPage = $this->getJson("/api/cards/due?deck_id={$deck->id}&per_page=1");

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
        $this->travelTo(Carbon::parse('2026-06-04T12:00:00Z'));
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        Card::factory()
            ->count(3)
            ->for($deck)
            ->create([
                'study_status' => CardStudyStatus::Review,
                'due_at' => now()->subHour(),
            ]);

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/cards/due');
    }
}
