<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\StartStudyLessonAction;
use App\Domain\Study\Actions\StartStudySessionAction;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionCourseFilteringActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_filters_due_session_cards_by_course_id(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        $outsideCourseDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $firstCourseCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subMinutes(30),
        ]);
        $secondCourseCard = $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subMinutes(20),
        ]);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
            courseId: strtoupper($course->id),
        );

        $this->assertSame(
            [$firstCourseCard->id, $secondCourseCard->id],
            $result->cards->pluck('id')->all(),
        );
        $this->assertSame(2, $result->overview['due_count']);
        $this->assertSame(2, $result->overview['total_cards']);
    }

    public function test_it_filters_lesson_cards_by_course_id_and_keeps_daily_guidance_user_wide(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        $outsideCourseDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $firstCourseCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCourseCard = $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::Review, [
            'introduced_at' => $now->copy()->subHour(),
            'due_at' => $now->copy()->addDay(),
        ]);

        $result = app(StartStudyLessonAction::class)->handle(
            userId: $user->id,
            now: $now,
            courseId: $course->id,
        );

        $this->assertSame(
            [$firstCourseCard->id, $secondCourseCard->id],
            $result->cards->pluck('id')->all(),
        );
        $this->assertSame(2, $result->overview['new_count']);
        $this->assertSame(1, $result->overview['new_cards_introduced_today']);
        $this->assertSame(1, $result->overview['new_cards_available_today']);
    }
}
