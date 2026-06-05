<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Actions\StartStudySessionAction;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_due_cards_block_new_cards_and_are_returned_in_due_order(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $secondDueCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
            'due_at' => $now->copy()->subMinute(),
        ]);
        $firstDueCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame([$firstDueCard->id, $secondDueCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(2, $result->overview['due_count']);
        $this->assertSame(0, $result->overview['new_cards_available_today']);
    }

    public function test_new_cards_use_remaining_daily_allowance_for_the_requested_time_zone(): void
    {
        $now = Carbon::parse('2026-06-04T03:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 3,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'introduced_at' => Carbon::parse('2026-06-03T05:00:00Z'),
            'due_at' => $now->copy()->addDay(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'introduced_at' => Carbon::parse('2026-06-02T23:00:00Z'),
            'due_at' => $now->copy()->addDay(),
        ]);
        $firstNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            timeZone: 'America/New_York',
            now: $now,
        );

        $this->assertSame([$firstNewCard->id, $secondNewCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(1, $result->overview['new_cards_introduced_today']);
        $this->assertSame(2, $result->overview['new_cards_available_today']);
    }

    public function test_ready_failed_cards_block_new_cards_and_are_returned_even_when_due_count_is_zero(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $readyFailedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->subMinutes(10),
            'failed_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame([$readyFailedCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(0, $result->overview['due_count']);
        $this->assertSame(1, $result->overview['failed_count']);
        $this->assertSame(1, $result->overview['failed_due_count']);
        $this->assertSame(0, $result->overview['new_cards_available_today']);
    }

    public function test_regular_due_and_ready_failed_cards_are_returned_together_with_separate_counts(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $readyFailedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->subMinutes(20),
            'failed_at' => $now->copy()->subHour(),
        ]);
        $regularDueCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subMinutes(10),
            'failed_at' => null,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        // Session order is due-date order across regular due and ready-failed cards.
        $this->assertSame([$readyFailedCard->id, $regularDueCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(1, $result->overview['due_count']);
        $this->assertSame(1, $result->overview['failed_count']);
        $this->assertSame(1, $result->overview['failed_due_count']);
        $this->assertSame(0, $result->overview['new_cards_available_today']);
    }

    public function test_ready_failed_cards_outside_the_deck_filter_do_not_change_the_deck_session(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Relearning, [
            'due_at' => $now->copy()->subMinutes(10),
            'failed_at' => $now->copy()->subHour(),
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: $deck->id,
        );

        $this->assertSame([$targetDeckCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(0, $result->overview['due_count']);
        $this->assertSame(0, $result->overview['failed_count']);
        $this->assertSame(0, $result->overview['failed_due_count']);
        $this->assertSame(1, $result->overview['new_cards_available_today']);
    }

    public function test_it_requires_the_internal_failed_due_count_overview_key(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study overview is missing failed_due_count.');

        $this->startStudySessionWithOverview([
            'due_count' => 0,
            'new_cards_available_today' => 0,
        ])->handle(
            userId: User::factory()->create()->id,
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );
    }

    public function test_it_requires_the_internal_due_count_overview_key(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study overview is missing due_count.');

        $this->startStudySessionWithOverview([
            'failed_due_count' => 0,
            'new_cards_available_today' => 0,
        ])->handle(
            userId: User::factory()->create()->id,
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );
    }

    public function test_it_only_uses_owned_cards_from_active_decks(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeck->delete();
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $ownedNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame([$ownedNewCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(0, $result->overview['due_count']);
        $this->assertSame(1, $result->overview['total_cards']);
    }

    public function test_it_filters_due_session_cards_by_deck_id(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subMinutes(30),
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: strtoupper($deck->id),
        );

        $this->assertSame([$targetDeckCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(1, $result->overview['due_count']);
        $this->assertSame(1, $result->overview['total_cards']);
    }

    public function test_it_filters_new_session_cards_by_deck_id_and_keeps_the_daily_allowance_user_wide(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'introduced_at' => $now->copy()->subHour(),
            'due_at' => $now->copy()->addDay(),
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: $deck->id,
        );

        $this->assertSame([$targetDeckCard->id], $result->cards->pluck('id')->all());
        $this->assertSame(2, $result->overview['new_count']);
        $this->assertSame(1, $result->overview['new_cards_introduced_today']);
        $this->assertSame(1, $result->overview['new_cards_available_today']);
    }

    public function test_it_returns_empty_session_for_another_users_deck_id(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        StudySettings::factory()->for($user)->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: $otherDeck->id,
        );

        $this->assertTrue($result->cards->isEmpty());
        $this->assertSame(0, $result->overview['due_count']);
        $this->assertSame(0, $result->overview['total_cards']);
    }

    public function test_new_cards_are_capped_by_the_server_owned_session_limit(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 1000,
        ]);

        for ($position = 1; $position <= StartStudySessionAction::READY_CARD_LIMIT + 2; $position++) {
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => $position,
            ]);
        }

        $result = app(StartStudySessionAction::class)->handle($user->id);

        $this->assertCount(StartStudySessionAction::READY_CARD_LIMIT, $result->cards);
        $this->assertSame(StartStudySessionAction::READY_CARD_LIMIT + 2, $result->overview['new_count']);
        $this->assertSame(StartStudySessionAction::READY_CARD_LIMIT + 2, $result->overview['new_cards_available_today']);
    }

    public function test_it_rejects_invalid_time_zones_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study time_zone must be a valid IANA timezone.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            timeZone: 'Not/A_Zone',
        );
    }

    public function test_it_rejects_blank_deck_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study deck_id filter must not be blank when provided.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: '   ',
        );
    }

    /**
     * @param  array<string, mixed>  $overview
     */
    private function startStudySessionWithOverview(array $overview): StartStudySessionAction
    {
        return new StartStudySessionAction(
            new class($overview) extends GetStudyOverviewAction
            {
                /**
                 * @param  array<string, mixed>  $overview
                 */
                public function __construct(private readonly array $overview) {}

                /**
                 * @return array<string, mixed>
                 */
                public function handle(
                    int $userId,
                    ?string $timeZone = null,
                    ?Carbon $now = null,
                    ?string $deckId = null,
                ): array {
                    return $this->overview;
                }
            },
        );
    }
}
