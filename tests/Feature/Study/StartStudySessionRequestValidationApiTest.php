<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionRequestValidationApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_start_validates_time_zone_without_coercing_malformed_values(): void
    {
        $this->signIn();

        $this->assertStartValidationError(['time_zone' => 'Not/A_Zone'], 'time_zone');
        $this->assertStartValidationError(['time_zone' => ['America/New_York']], 'time_zone');
        $this->assertStartValidationError(['timeZone' => 'Not/A_Zone'], 'timeZone');
        $this->assertStartValidationError(['timeZone' => ['America/New_York']], 'timeZone');
    }

    public function test_session_start_uses_the_canonical_time_zone_for_the_study_day(): void
    {
        $this->assertCanonicalStudyDay('/api/study/session/start');
    }

    public function test_lesson_start_uses_the_canonical_time_zone_for_the_study_day(): void
    {
        $this->assertCanonicalStudyDay('/api/study/lessons/start');
    }

    public function test_session_and_lesson_start_reject_conflicting_time_zone_names(): void
    {
        $this->signIn();

        foreach (['/api/study/session/start', '/api/study/lessons/start'] as $endpoint) {
            $this->postJson($endpoint, [
                'timeZone' => 'America/New_York',
                'time_zone' => 'Asia/Tokyo',
            ])->assertUnprocessable()->assertJsonValidationErrors(['timeZone']);
        }
    }

    public function test_start_treats_explicit_null_and_a_populated_alias_as_a_conflict(): void
    {
        $this->signIn();

        $this->assertStartValidationError([
            'timeZone' => null,
            'time_zone' => 'America/New_York',
        ], 'timeZone');
        $this->postJson('/api/study/session/start', [
            'timeZone' => null,
            'time_zone' => null,
        ])->assertOk();
    }

    public function test_lesson_start_rejects_malformed_time_zone_shapes(): void
    {
        $this->signIn();

        $this->assertValidationError('/api/study/lessons/start', ['timeZone' => ['UTC']], 'timeZone');
        $this->assertValidationError('/api/study/lessons/start', ['time_zone' => ['UTC']], 'time_zone');
        $this->assertValidationError('/api/study/lessons/start', ['timeZone' => 'Not/A_Zone'], 'timeZone');
    }

    public function test_start_normalizes_both_time_zone_names_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'timeZone' => '  America/New_York  ',
            'time_zone' => '  America/New_York  ',
        ])->assertOk();
    }

    public function test_start_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $this->assertStartValidationError(['deck_id' => 'not-a-ulid'], 'deck_id');
        $this->assertStartValidationError(['deck_id' => ['01J00000000000000000000000']], 'deck_id');
        $this->assertStartValidationError(['deckId' => 'not-a-ulid'], 'deckId');
        $this->assertStartValidationError(['courseId' => 'not-a-ulid'], 'courseId');
        $this->assertStartValidationError(['course_id' => 'not-a-ulid'], 'course_id');
        $this->assertStartValidationError(['course_id' => ['01J00000000000000000000000']], 'course_id');
    }

    public function test_start_rejects_conflicting_camel_and_legacy_scope_filters(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $this->assertStartValidationError([
            'courseId' => $course->id,
            'course_id' => $otherCourse->id,
        ], 'courseId');
        $this->assertStartValidationError([
            'deckId' => $deck->id,
            'deck_id' => $otherDeck->id,
        ], 'deckId');
    }

    public function test_start_rejects_blank_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->signIn();

        $this->assertStartValidationError(['deck_id' => '   '], 'deck_id');
        $this->assertStartValidationError(['deckId' => '   '], 'deckId');
        $this->assertStartValidationError(['courseId' => '   '], 'courseId');
        $this->assertStartValidationError(['course_id' => '   '], 'course_id');
    }

    private function assertCanonicalStudyDay(string $endpoint): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T03:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create(['new_cards_per_day' => 1]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
                'introduced_at' => Carbon::parse('2026-06-03T22:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);

            $this->postJson($endpoint, ['timeZone' => 'America/New_York'])
                ->assertOk()
                ->assertJsonPath('overview.newCardsIntroducedToday', 1)
                ->assertJsonPath('overview.newCardsAvailableToday', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertStartValidationError(array $payload, string $field): void
    {
        $this->assertValidationError('/api/study/session/start', $payload, $field);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertValidationError(string $endpoint, array $payload, string $field): void
    {
        $this->postJson($endpoint, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }
}
