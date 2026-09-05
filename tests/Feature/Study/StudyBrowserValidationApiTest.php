<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyBrowserValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_browser_query_inputs_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 3001,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => '会社 company',
        ]);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?q=%20%E4%BC%9A%E7%A4%BE%20&cardType=%20RECOGNITION%20&queueState=%20REVIEW%20&sortField=%20CREATED_ON%20&sortDirection=%20DESC%20&limit=%20%2B1%20')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('rows.0.noteId', '3001');
    }

    public function test_it_rejects_blank_text_filters_without_trim_strings_middleware(): void
    {
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?q=%20%20%20')
            ->assertJsonValidationErrors(['q']);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?noteType=%20%20%20')
            ->assertJsonValidationErrors(['noteType']);
    }

    public function test_it_validates_browser_query_inputs(): void
    {
        $this->signIn();

        $this->assertSortAndLimitValidation();
        $this->assertFilterShapeValidation();
        $this->assertScopeAndCursorValidation();
    }

    private function assertSortAndLimitValidation(): void
    {
        $this->getJson('/api/study/browser?sortField=bad&sortDirection=sideways')
            ->assertJsonValidationErrors(['sortField', 'sortDirection']);
        $this->getJson('/api/study/browser?q='.str_repeat('a', 201))
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/study/browser?noteType='.str_repeat('a', 201))
            ->assertJsonValidationErrors(['noteType']);
        $this->getJson('/api/study/browser?limit=0')
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?limit=101')
            ->assertJsonValidationErrors(['limit']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?limit=%20-1%20')
            ->assertJsonValidationErrors(['limit']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?limit=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?limit=abc')
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?limit[]=25')
            ->assertJsonValidationErrors(['limit']);
    }

    private function assertFilterShapeValidation(): void
    {
        $this->getJson('/api/study/browser?q[]=company')
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/study/browser?noteType[]=Japanese')
            ->assertJsonValidationErrors(['noteType']);
        $this->getJson('/api/study/browser?cardType[]=recognition')
            ->assertJsonValidationErrors(['cardType']);
        $this->getJson('/api/study/browser?queueState[]=review')
            ->assertJsonValidationErrors(['queueState']);
        $this->getJson('/api/study/browser?sortField[]=created_on')
            ->assertJsonValidationErrors(['sortField']);
        $this->getJson('/api/study/browser?sortDirection[]=desc')
            ->assertJsonValidationErrors(['sortDirection']);
        $this->getJson('/api/study/browser?courseId=not-a-ulid')
            ->assertJsonValidationErrors(['courseId']);
        $this->getJson('/api/study/browser?courseId[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['courseId']);
        $this->getJson('/api/study/browser?course_id=not-a-ulid')
            ->assertJsonValidationErrors(['course_id']);
        $this->getJson('/api/study/browser?course_id[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['course_id']);
        $this->getJson('/api/study/browser?deckId=not-a-ulid')
            ->assertJsonValidationErrors(['deckId']);
        $this->getJson('/api/study/browser?deckId[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['deckId']);
        $this->getJson('/api/study/browser?deck_id=not-a-ulid')
            ->assertJsonValidationErrors(['deck_id']);
        $this->getJson('/api/study/browser?deck_id[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['deck_id']);
    }

    private function assertScopeAndCursorValidation(): void
    {
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?courseId=%20')
            ->assertJsonValidationErrors(['courseId']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?course_id=%20')
            ->assertJsonValidationErrors(['course_id']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?deckId=%20')
            ->assertJsonValidationErrors(['deckId']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?deck_id=%20')
            ->assertJsonValidationErrors(['deck_id']);
        $this->getJson('/api/study/browser?cursor=')
            ->assertJsonValidationErrors(['cursor']);
        $this->getJson('/api/study/browser?cursor[]=abc')
            ->assertJsonValidationErrors(['cursor']);
        $this->getJson('/api/study/browser?cursor=not-a-cursor')
            ->assertJsonValidationErrors(['cursor']);
    }

    public function test_it_rejects_conflicting_browser_scope_filter_aliases(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $this->getJson("/api/study/browser?courseId={$course->id}&course_id={$otherCourse->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->getJson("/api/study/browser?deckId={$deck->id}&deck_id={$otherDeck->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/browser')
            ->assertUnauthorized();
    }
}
