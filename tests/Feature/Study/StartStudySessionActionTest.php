<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\StartStudyLessonAction;
use App\Domain\Study\Actions\StartStudySessionAction;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subDay(),
            'variant_status' => VocabVariantStatus::Locked->value,
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
        $this->assertSame(1, $result->overview['new_cards_available_today']);
    }

    public function test_review_session_returns_due_backlogs_beyond_the_retired_300_card_cap(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        for ($offset = 0; $offset < 301; $offset++) {
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
                'due_at' => $now->copy()->subMinutes(301 - $offset),
            ]);
        }

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertCount(301, $result->cards);
        $this->assertSame(301, $result->overview['due_count']);
    }

    public function test_review_session_is_empty_when_only_new_lesson_cards_exist(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $result = app(StartStudySessionAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertCount(0, $result->cards);
        $this->assertSame(1, $result->overview['new_cards_available_today']);
    }

    public function test_lessons_can_use_queued_cards_beyond_the_daily_guidance_allowance(): void
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
        $thirdNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 0,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $result = app(StartStudyLessonAction::class)->handle(
            userId: $user->id,
            timeZone: 'America/New_York',
            now: $now,
        );

        $this->assertSame(
            [$firstNewCard->id, $secondNewCard->id, $thirdNewCard->id],
            $result->cards->pluck('id')->all(),
        );
        $this->assertSame(1, $result->overview['new_cards_introduced_today']);
        $this->assertSame(2, $result->overview['new_cards_available_today']);
    }

    public function test_legacy_null_position_cards_do_not_consume_new_session_slots(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => null,
        ]);
        $firstPositionedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondPositionedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $result = app(StartStudyLessonAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame(
            [$firstPositionedCard->id, $secondPositionedCard->id],
            $result->cards->pluck('id')->all(),
        );
        $this->assertSame(2, $result->overview['new_count']);
        $this->assertSame(2, $result->overview['new_cards_available_today']);
    }

    public function test_ready_failed_cards_block_new_cards_and_are_returned_even_when_due_count_is_zero(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        [$user, $deck] = $this->userAndDeckWithDailyLimit(20);
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
        $this->assertSame(1, $result->overview['new_cards_available_today']);
    }

    public function test_regular_due_and_ready_failed_cards_are_returned_together_with_separate_counts(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        [$user, $deck] = $this->userAndDeckWithDailyLimit(20);
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
        $this->assertSame(1, $result->overview['new_cards_available_today']);
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

        $result = app(StartStudyLessonAction::class)->handle(
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

    /**
     * @return array{0: User, 1: Deck}
     */
    private function userAndDeckWithDailyLimit(int $newCardsPerDay): array
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => $newCardsPerDay,
        ]);

        return [$user, $deck];
    }
}
