<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
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

    public function test_it_reports_fsrs_mastery_spread_and_advisory_learning_readiness(): void
    {
        $now = Carbon::parse('2026-07-27T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'lesson_batch_size' => 8,
        ]);
        $guruCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 7],
            'due_at' => $now->copy()->addDay(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
            'due_at' => $now->copy()->addYear(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'scheduler_state' => ['stability' => 365],
            'due_at' => $now->copy()->addMinutes(10),
        ]);

        for ($review = 0; $review < 30; $review++) {
            CardReviewEvent::factory()->for($guruCard, 'card')->create([
                'rating' => $review < 10 ? CardReviewRating::Again : CardReviewRating::Good,
                'reviewed_at' => $now->copy()->subMinutes($review),
            ]);
        }

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame([
            'apprentice' => 1,
            'guru' => 1,
            'master' => 0,
            'enlightened' => 0,
            'burned' => 1,
        ], $overview['mastery_spread']);
        $this->assertSame('pause', $overview['learning_readiness']['recommendation']);
        $this->assertSame('pause', $overview['learning_readiness']['readiness_level']);
        $this->assertSame(30, $overview['learning_readiness']['sample_size']);
        $this->assertSame(0.667, $overview['learning_readiness']['recent_recall']);
        $this->assertSame(3, $overview['learning_readiness']['suggested_batch_size']);
    }

    public function test_it_reports_separate_n5_vocabulary_and_grammar_mastery_using_the_strongest_linked_card(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $weakVocabularyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 7],
        ]);
        $strongVocabularyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
        ]);
        $grammarCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 90],
        ]);

        foreach ([$weakVocabularyCard, $strongVocabularyCard] as $card) {
            DB::table('card_learning_concepts')->insert([
                'card_id' => $card->id,
                'concept_id' => 'n5-vocab-1198550-2120ff50',
                'match_method' => 'exact',
                'match_source' => 'backfill',
                'confidence' => 1,
                'classifier_version' => 'n5-rules-v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('card_learning_concepts')->insert([
            'card_id' => $grammarCard->id,
            'concept_id' => 'n5-grammar-arimasu-existence-inanimate',
            'match_method' => 'surface',
            'match_source' => 'backfill',
            'confidence' => 0.7,
            'classifier_version' => 'n5-rules-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherUserCard = $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::Review,
            ['scheduler_state' => ['stability' => 365]],
        );
        $deletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
        ]);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
        ]);
        $guruCardInOtherActiveDeck = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['scheduler_state' => ['stability' => 7]],
        );
        $lockedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        foreach ([$otherUserCard, $deletedCard, $deletedDeckCard, $lockedCard] as $excludedCard) {
            DB::table('card_learning_concepts')->insert([
                'card_id' => $excludedCard->id,
                'concept_id' => 'n5-vocab-1198180-ada066ed',
                'match_method' => 'exact',
                'match_source' => 'backfill',
                'confidence' => 1,
                'classifier_version' => 'n5-rules-v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('card_learning_concepts')->insert([
            'card_id' => $guruCardInOtherActiveDeck->id,
            'concept_id' => 'n5-vocab-1381380-ebec6584',
            'match_method' => 'exact',
            'match_source' => 'backfill',
            'confidence' => 1,
            'classifier_version' => 'n5-rules-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedCard->delete();
        $deletedDeck->delete();

        $otherUser = User::factory()->create();
        DB::table('wanikani_subjects')->insert([
            [
                'subject_id' => 9001,
                'subject_type' => 'vocabulary',
                'characters' => '上げる',
                'normalized_key' => '上げる',
                'readings' => json_encode(['あげる'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['to raise'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subject_id' => 9002,
                'subject_type' => 'vocabulary',
                'characters' => '赤',
                'normalized_key' => '赤',
                'readings' => json_encode(['あか'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['red'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subject_id' => 9003,
                'subject_type' => 'vocabulary',
                'characters' => '青',
                'normalized_key' => '青',
                'readings' => json_encode(['あお'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['blue'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('wanikani_subject_learning_concepts')->insert([
            ['subject_id' => 9001, 'concept_id' => 'n5-vocab-1198550-2120ff50', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
            ['subject_id' => 9002, 'concept_id' => 'n5-vocab-1198180-ada066ed', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
            ['subject_id' => 9003, 'concept_id' => 'n5-vocab-1275320-9949d874', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('user_wanikani_assignments')->insert([
            ['user_id' => $user->id, 'subject_id' => 9001, 'srs_stage' => 5, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'subject_id' => 9002, 'srs_stage' => 7, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $otherUser->id, 'subject_id' => 9003, 'srs_stage' => 9, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $n5 = app(GetStudyOverviewAction::class)->handle(userId: $user->id)['jlpt_mastery']['N5'];
        $deckN5 = app(GetStudyOverviewAction::class)->handle(userId: $user->id, deckId: $deck->id)['jlpt_mastery']['N5'];

        $this->assertSame(['mastery_percent' => 0, 'known' => 3, 'known_from_cards' => 2, 'known_from_wanikani' => 2, 'known_from_both' => 1, 'matched' => 2, 'covered' => 2, 'total' => 684], $n5['vocabulary']);
        $this->assertSame(['mastery_percent' => 1, 'known' => 1, 'known_from_cards' => 1, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 1, 'covered' => 1, 'total' => 77], $n5['grammar']);
        $this->assertSame(['mastery_percent' => 0, 'known' => 1, 'known_from_cards' => 1, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 1, 'covered' => 1, 'total' => 684], $deckN5['vocabulary']);
        $this->assertSame(['mastery_percent' => 1, 'known' => 1, 'known_from_cards' => 1, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 1, 'covered' => 1, 'total' => 77], $deckN5['grammar']);
        $this->assertArrayNotHasKey('overall', $n5);
    }

    public function test_readiness_uses_recall_and_projected_review_time_instead_of_raw_due_count(): void
    {
        $now = Carbon::parse('2026-08-05T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $settings = StudySettings::factory()->for($user)->create([
            'lesson_batch_size' => 8,
            'review_time_budget_minutes' => 120,
        ]);
        $reviewedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subDay(),
        ]);

        for ($card = 1; $card < 70; $card++) {
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
                'due_at' => $now->copy()->subDay(),
            ]);
        }

        for ($review = 0; $review < 40; $review++) {
            CardReviewEvent::factory()->for($reviewedCard, 'card')->create([
                'rating' => $review < 2 ? CardReviewRating::Again : CardReviewRating::Good,
                'reviewed_at' => $now->copy()->subMinutes($review),
                'duration_ms' => 600_000,
            ]);
        }

        $withinBudget = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame(70, $withinBudget['learning_readiness']['due_backlog']);
        $this->assertSame(70, $withinBudget['learning_readiness']['projected_seven_day_reviews']);
        $this->assertSame(600.0, $withinBudget['learning_readiness']['median_review_duration_seconds']);
        $this->assertSame(100, $withinBudget['learning_readiness']['projected_daily_review_minutes']);
        $this->assertSame(20, $withinBudget['learning_readiness']['review_time_headroom_minutes']);
        $this->assertSame('ready', $withinBudget['learning_readiness']['readiness_level']);
        $this->assertSame('ready', $withinBudget['learning_readiness']['recommendation']);

        $settings->review_time_budget_minutes = 110;
        $settings->saveOrFail();

        $nearBudget = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame('steady', $nearBudget['learning_readiness']['readiness_level']);
        $this->assertSame(8, $nearBudget['learning_readiness']['suggested_batch_size']);

        $settings->review_time_budget_minutes = 90;
        $settings->saveOrFail();

        $overBudget = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame('ease_up', $overBudget['learning_readiness']['readiness_level']);
        $this->assertSame('caution', $overBudget['learning_readiness']['recommendation']);
        $this->assertSame(-10, $overBudget['learning_readiness']['review_time_headroom_minutes']);

        CardReviewEvent::query()->where('card_id', $reviewedCard->id)->update([
            'rating' => CardReviewRating::Good->value,
        ]);
        $settings->review_time_budget_minutes = 130;
        $settings->saveOrFail();

        $strong = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame(1.0, $strong['learning_readiness']['recent_recall']);
        $this->assertSame(30, $strong['learning_readiness']['review_time_headroom_minutes']);
        $this->assertSame('strong', $strong['learning_readiness']['readiness_level']);
        $this->assertSame(8, $strong['learning_readiness']['suggested_batch_size']);
    }

    public function test_readiness_does_not_claim_strong_capacity_before_timing_is_calibrated(): void
    {
        $now = Carbon::parse('2026-08-05T12:00:00Z');
        $user = User::factory()->create();
        $card = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['due_at' => $now->copy()->addDay()],
        );

        for ($review = 0; $review < 30; $review++) {
            CardReviewEvent::factory()->for($card, 'card')->create([
                'rating' => CardReviewRating::Good,
                'reviewed_at' => $now->copy()->subMinutes($review),
                'duration_ms' => null,
            ]);
        }

        $readiness = app(GetStudyOverviewAction::class)
            ->handle(userId: $user->id, now: $now)['learning_readiness'];

        $this->assertSame(1.0, $readiness['recent_recall']);
        $this->assertNull($readiness['projected_daily_review_minutes']);
        $this->assertSame('ready', $readiness['readiness_level']);
    }

    public function test_lightweight_overview_omits_guidance_for_hot_write_responses(): void
    {
        $user = User::factory()->create();
        $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['scheduler_state' => ['stability' => 30]],
        );
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $overview = app(GetStudyOverviewAction::class)->handle(
                userId: $user->id,
                includeGuidance: false,
            );
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertArrayNotHasKey('mastery_spread', $overview);
        $this->assertArrayNotHasKey('jlpt_mastery', $overview);
        $this->assertArrayNotHasKey('learning_readiness', $overview);
        $this->assertCount(2, $queries);
    }

    public function test_untouched_new_cards_do_not_inflate_apprentice_load_or_readiness(): void
    {
        $now = Carbon::parse('2026-07-27T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        for ($position = 1; $position <= 100; $position++) {
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => $position,
            ]);
        }
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Buried);

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame([
            'apprentice' => 0,
            'guru' => 0,
            'master' => 0,
            'enlightened' => 0,
            'burned' => 0,
        ], $overview['mastery_spread']);
        $this->assertSame(0, $overview['learning_readiness']['apprentice_count']);
        $this->assertSame('baseline', $overview['learning_readiness']['readiness_level']);
        $this->assertSame('ready', $overview['learning_readiness']['recommendation']);
    }

    public function test_due_count_excludes_failed_cards_without_blocking_separate_lessons(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $readyFailedCardDueAt = $now->copy()->subMinutes(10);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => $readyFailedCardDueAt,
            'failed_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(1, $overview['failed_count']);
        $this->assertSame(1, $overview['failed_due_count']);
        $this->assertSame(1, $overview['new_count']);
        $this->assertSame(1, $overview['new_cards_available_today']);
        $this->assertSame($readyFailedCardDueAt->toJSON(), $overview['next_due_at']);
    }

    public function test_future_failed_cards_do_not_block_new_cards(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->addHour(),
            'failed_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(1, $overview['failed_count']);
        $this->assertSame(0, $overview['failed_due_count']);
        $this->assertSame(1, $overview['new_cards_available_today']);
    }

    public function test_ready_failed_cards_outside_the_deck_filter_do_not_block_new_cards(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->subMinutes(10),
            'failed_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: $deck->id,
        );

        $this->assertSame(0, $overview['due_count']);
        $this->assertSame(0, $overview['failed_count']);
        $this->assertSame(0, $overview['failed_due_count']);
        $this->assertSame(1, $overview['new_cards_available_today']);
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
