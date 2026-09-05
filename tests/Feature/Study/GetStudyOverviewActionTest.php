<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;
use UnexpectedValueException;

class GetStudyOverviewActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_new_count_excludes_cards_that_only_belong_to_a_disabled_lane(): void
    {
        $now = Carbon::parse('2026-08-26T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'lesson_followup_lane_weight' => 0,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'selection_policy' => CardSelectionPolicy::ReviewSoon,
            'priority_until' => $now->copy()->addWeek(),
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame(0, $overview['new_count']);
        $this->assertSame(0, $overview['new_cards_available_today']);
    }

    public function test_locked_progression_cards_are_excluded_from_scheduler_metrics(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create(['new_cards_per_day' => 20]);

        foreach ([CardStudyStatus::New, CardStudyStatus::Learning, CardStudyStatus::Review] as $index => $status) {
            $this->cardWithStudyStatus($deck, $status, [
                'new_queue_position' => $status === CardStudyStatus::New ? $index + 1 : null,
                'due_at' => $status === CardStudyStatus::New ? null : $now->copy()->subHour(),
                'failed_at' => $status === CardStudyStatus::New ? null : $now->copy()->subDay(),
                'variant_status' => VocabVariantStatus::Locked->value,
            ]);
        }
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended, [
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(0, $overview['failed_count']);
        $this->assertSame(0, $overview['failed_due_count']);
        $this->assertSame(0, $overview['new_count']);
        $this->assertSame(0, $overview['learning_count']);
        $this->assertSame(0, $overview['review_count']);
        $this->assertSame(0, $overview['suspended_count']);
        $this->assertNull($overview['next_due_at']);
        $this->assertSame(4, $overview['total_cards']);
        $this->assertSame([
            'apprentice' => 0,
            'guru' => 0,
            'master' => 0,
            'enlightened' => 0,
            'burned' => 0,
        ], $overview['mastery_spread']);
        $this->assertSame(0, $overview['learning_readiness']['projected_seven_day_reviews']);
    }

    public function test_it_returns_owned_active_card_counts_and_daily_new_card_allowance(): void
    {
        $now = Carbon::parse('2026-06-04T03:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeck->delete();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 3,
        ]);
        $nextDueAt = Carbon::parse('2026-06-04T05:00:00Z');
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
            'due_at' => $nextDueAt,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'introduced_at' => Carbon::parse('2026-06-03T05:00:00Z'),
            'due_at' => $now->copy()->addDay(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended);
        $softDeletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'introduced_at' => Carbon::parse('2026-06-03T08:00:00Z'),
            'due_at' => $now->copy()->subHour(),
        ]);
        $softDeletedCard->delete();
        $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'introduced_at' => Carbon::parse('2026-06-03T06:00:00Z'),
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
            'introduced_at' => Carbon::parse('2026-06-03T07:00:00Z'),
            'due_at' => $now->copy()->subHour(),
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            timeZone: 'America/New_York',
            now: $now,
        );

        $this->assertSame(1, $overview['due_count']);
        $this->assertSame(1, $overview['new_count']);
        $this->assertSame(1, $overview['new_cards_introduced_today']);
        $this->assertSame(1, $overview['new_cards_available_today']);
        $this->assertSame(1, $overview['learning_count']);
        $this->assertSame(2, $overview['review_count']);
        $this->assertSame(1, $overview['suspended_count']);
        $this->assertSame(5, $overview['total_cards']);
        $this->assertSame($now->copy()->subHour()->toJSON(), $overview['next_due_at']);
    }

    public function test_it_filters_overview_counts_by_deck_id_for_direct_callers(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $nextDueAt = $now->copy()->addHours(2);

        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $nextDueAt,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'introduced_at' => $now->copy()->subHour(),
            'due_at' => $now->copy()->addDay(),
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: strtoupper($deck->id),
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(2, $overview['new_count']);
        $this->assertSame(1, $overview['new_cards_introduced_today']);
        $this->assertSame(1, $overview['new_cards_available_today']);
        $this->assertSame(0, $overview['learning_count']);
        $this->assertSame(1, $overview['review_count']);
        $this->assertSame(1, $overview['suspended_count']);
        $this->assertSame(4, $overview['total_cards']);
        $this->assertSame($nextDueAt->toJSON(), $overview['next_due_at']);
    }

    public function test_it_filters_overview_counts_by_course_id_for_direct_callers(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        $outsideCourseDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 3,
        ]);
        $nextDueAt = $now->copy()->addHours(2);

        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::Review, [
            'due_at' => $nextDueAt,
        ]);
        $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::Suspended);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::Review, [
            'introduced_at' => $now->copy()->subHour(),
            'due_at' => $now->copy()->addDay(),
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
            courseId: strtoupper($course->id),
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(1, $overview['new_count']);
        $this->assertSame(1, $overview['new_cards_introduced_today']);
        $this->assertSame(1, $overview['new_cards_available_today']);
        $this->assertSame(0, $overview['learning_count']);
        $this->assertSame(1, $overview['review_count']);
        $this->assertSame(1, $overview['suspended_count']);
        $this->assertSame(3, $overview['total_cards']);
        $this->assertSame($nextDueAt->toJSON(), $overview['next_due_at']);
    }

    public function test_it_loads_overview_metrics_with_bounded_queries(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $nextDueAt = $now->copy()->subHour();
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $nextDueAt,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->subMinutes(10),
            'failed_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->addHour(),
            'failed_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $overview = app(GetStudyOverviewAction::class)->handle(
                userId: $user->id,
                now: $now,
            );
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(1, $overview['due_count']);
        $this->assertSame(2, $overview['failed_count']);
        $this->assertSame(1, $overview['failed_due_count']);
        $this->assertSame(1, $overview['new_count']);
        $this->assertSame(2, $overview['learning_count']);
        $this->assertSame(1, $overview['review_count']);
        $this->assertSame(1, $overview['suspended_count']);
        $this->assertSame(5, $overview['total_cards']);
        $this->assertSame($nextDueAt->toJSON(), $overview['next_due_at']);

        // Overview reads remain bounded as the collection grows: aggregate, card mastery,
        // JLPT mastery, recent recall, projected workload, and latest import.
        $this->assertCount(6, $queries, $queries->pluck('query')->implode("\n"));

        // Lock the conditional aggregate shape so bucket counts do not drift back to per-metric queries.
        $cardMetricQueries = $queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'COUNT(cards.id) AS total_cards'),
        );

        $this->assertCount(1, $cardMetricQueries, $queries->pluck('query')->implode("\n"));

        $settingsQueries = $queries->filter(fn (array $query): bool => str_contains($query['query'], 'study_settings'));

        $this->assertCount(1, $settingsQueries, $queries->pluck('query')->implode("\n"));
    }

    public function test_it_normalizes_database_timestamp_strings_from_overview_aggregates(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $card = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);

        DB::table('cards')
            ->where('id', $card->id)
            ->update(['due_at' => '2026-06-04 11:00:00']);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame('2026-06-04T11:00:00.000000Z', $overview['next_due_at']);
    }

    public function test_it_rejects_invalid_overview_aggregate_timestamps(): void
    {
        $action = app(GetStudyOverviewAction::class);
        $method = new ReflectionMethod($action, 'aggregateTimestamp');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study overview next_due_at aggregate is not a valid timestamp.');

        $method->invoke($action, 'tomorrow', 'next_due_at');
    }

    public function test_it_rejects_unexpected_overview_aggregate_timestamp_types(): void
    {
        $action = app(GetStudyOverviewAction::class);
        $method = new ReflectionMethod($action, 'aggregateTimestamp');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study overview next_due_at aggregate has an unexpected timestamp type.');

        $method->invoke($action, 123, 'next_due_at');
    }

    public function test_it_includes_the_latest_import_for_the_user(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        StudySettings::factory()->for($user)->create();
        $olderImport = StudyImportJob::factory()->completed()->for($user)->create([
            'created_at' => $now->copy()->subDay(),
        ]);
        $latestImport = StudyImportJob::factory()->failed()->for($user)->create([
            'source_filename' => 'latest.colpkg',
            'created_at' => $now,
        ]);
        StudyImportJob::factory()->completed()->for(User::factory()->create())->create([
            'created_at' => $now->copy()->addDay(),
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertInstanceOf(StudyImportJob::class, $overview['latest_import']);
        $this->assertSame($latestImport->id, $overview['latest_import']->id);
        $this->assertNotSame($olderImport->id, $overview['latest_import']->id);
    }

    public function test_it_returns_null_latest_import_when_the_user_has_no_imports(): void
    {
        $user = User::factory()->create();
        StudySettings::factory()->for($user)->create();

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id);

        $this->assertNull($overview['latest_import']);
    }

    public function test_it_uses_default_settings_without_materializing_them_on_overview_reads(): void
    {
        $user = User::factory()->create();

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id);

        $this->assertSame(StudySettings::DEFAULT_NEW_CARDS_PER_DAY, $overview['new_cards_per_day']);
        $this->assertSame(
            StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
            $overview['review_time_budget_minutes'],
        );
        $this->assertSame(0, $overview['new_cards_available_today']);
        $this->assertDatabaseMissing('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        ]);
    }

    public function test_it_returns_explicit_new_cards_per_day_settings_from_overview_reads(): void
    {
        $user = User::factory()->create();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 5,
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id);

        $this->assertSame(5, $overview['new_cards_per_day']);
    }

    public function test_it_returns_empty_overview_for_another_users_deck_id(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        StudySettings::factory()->for($user)->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: $otherDeck->id,
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(0, $overview['failed_count']);
        $this->assertSame(0, $overview['failed_due_count']);
        $this->assertSame(0, $overview['new_count']);
        $this->assertSame(0, $overview['new_cards_available_today']);
        $this->assertSame(0, $overview['learning_count']);
        $this->assertSame(0, $overview['review_count']);
        $this->assertSame(0, $overview['suspended_count']);
        $this->assertSame(0, $overview['total_cards']);
        $this->assertNull($overview['next_due_at']);
    }

    public function test_it_rejects_invalid_time_zones_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study time_zone must be a valid IANA timezone.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            timeZone: 'Not/A_Zone',
        );
    }

    public function test_it_rejects_blank_deck_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study overview deckId filter must not be blank when provided.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: '   ',
        );
    }

    public function test_it_rejects_invalid_deck_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study overview deckId filter must be a valid ULID.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_blank_course_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study overview courseId filter must not be blank when provided.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: '   ',
        );
    }

    public function test_it_rejects_invalid_course_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study overview courseId filter must be a valid ULID.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: 'not-a-ulid',
        );
    }
}
