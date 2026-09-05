<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCardsDeckFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_cards_by_deck_id(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $deckCard = Card::factory()->for($deck)->create([
            'front_text' => 'ciao',
        ]);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherUserCard = $this->cardFor(User::factory()->create());

        $response = $this->getJson("/api/cards?deck_id={$deck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckCard->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonPath('data.0.front_text', 'ciao')
            ->assertJsonMissing([
                'id' => $otherDeckCard->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUserCard->id,
            ]);
    }

    public function test_it_requires_deck_id_filters_to_match_the_course_filter_when_both_are_provided(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();

        $response = $this->getJson("/api/cards?course_id={$course->id}&deck_id={$otherCourseDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherCourseCard->id,
            ]);
    }

    public function test_it_trims_deck_id_filters_without_global_trim_middleware(): void
    {
        $this->assertNormalizedDeckIdFilter(fn (string $id): string => '%20'.$id.'%20');
    }

    public function test_it_lowercases_deck_id_filters_without_global_trim_middleware(): void
    {
        $this->assertNormalizedDeckIdFilter(fn (string $id): string => strtoupper($id));
    }

    public function test_it_returns_an_empty_list_for_another_users_deck_id(): void
    {
        $this->signIn();
        $otherUserDeck = $this->deckFor(User::factory()->create());
        $otherUserCard = Card::factory()->for($otherUserDeck)->create();

        $response = $this->getJson("/api/cards?deck_id={$otherUserDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherUserCard->id,
            ]);
    }

    public function test_it_rejects_a_blank_deck_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?deck_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_a_malformed_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?deck_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_an_array_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?deck_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    /** @param callable(string): string $queryValue */
    private function assertNormalizedDeckIdFilter(callable $queryValue): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $deckCard = Card::factory()->for($deck)->create();
        $otherDeckCard = Card::factory()->for($otherDeck)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?deck_id='.$queryValue($deck->id));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckCard->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonMissing([
                'id' => $otherDeckCard->id,
            ]);
    }
}
