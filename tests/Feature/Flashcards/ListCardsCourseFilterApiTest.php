<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCardsCourseFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_cards_by_course_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $otherCourse = Course::factory()->create(['user_id' => $user->id]);
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        $standaloneDeck = $this->deckFor($user);
        $courseCard = Card::factory()->for($courseDeck)->create([
            'front_text' => 'ciao',
        ]);
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $standaloneCard = Card::factory()->for($standaloneDeck)->create();

        $response = $this->getJson("/api/cards?course_id={$course->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseCard->id)
            ->assertJsonPath('data.0.deck_id', $courseDeck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonPath('data.0.front_text', 'ciao')
            ->assertJsonMissing([
                'id' => $otherCourseCard->id,
            ])
            ->assertJsonMissing([
                'id' => $standaloneCard->id,
            ]);
    }

    public function test_it_trims_course_id_filters_without_global_trim_middleware(): void
    {
        $this->assertNormalizedCourseIdFilter(fn (string $id): string => '%20'.$id.'%20');
    }

    public function test_it_lowercases_course_id_filters_without_global_trim_middleware(): void
    {
        $this->assertNormalizedCourseIdFilter(fn (string $id): string => strtoupper($id));
    }

    public function test_it_returns_an_empty_list_for_another_users_course_id(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherCourse = Course::factory()->create(['user_id' => $otherUser->id]);
        $otherCourseDeck = $this->deckFor($otherUser, ['course_id' => $otherCourse->id]);
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();

        $response = $this->getJson("/api/cards?course_id={$otherCourse->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherCourseCard->id,
            ]);
    }

    public function test_it_rejects_a_blank_course_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?course_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_a_malformed_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?course_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_an_array_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?course_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    /** @param callable(string): string $queryValue */
    private function assertNormalizedCourseIdFilter(callable $queryValue): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $standaloneDeck = $this->deckFor($user);
        $courseCard = Card::factory()->for($courseDeck)->create();
        $standaloneCard = Card::factory()->for($standaloneDeck)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?course_id='.$queryValue($course->id));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseCard->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing([
                'id' => $standaloneCard->id,
            ]);
    }
}
