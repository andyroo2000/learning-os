<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class GetStudyOverviewActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

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
        $this->assertSame(0, $overview['new_cards_available_today']);
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

    public function test_it_loads_overview_card_metrics_with_one_aggregate_query(): void
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

        // Normal overview reads stay at settings + card aggregate + latest import.
        $this->assertCount(3, $queries, $queries->pluck('query')->implode("\n"));

        // Lock the conditional aggregate shape so bucket counts do not drift back to per-metric queries.
        $cardMetricQueries = $queries->filter(fn (array $query): bool => str_contains($query['query'], 'SUM(CASE WHEN cards.study_status'));

        $this->assertCount(1, $cardMetricQueries, $queries->pluck('query')->implode("\n"));
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

    public function test_due_count_excludes_failed_cards_but_ready_failed_cards_block_new_cards(): void
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
        $this->assertSame(0, $overview['new_cards_available_today']);
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
        $this->expectExceptionMessage('Study deck_id filter must not be blank when provided.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: '   ',
        );
    }
}
