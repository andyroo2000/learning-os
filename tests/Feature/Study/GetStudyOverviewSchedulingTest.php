<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class GetStudyOverviewSchedulingTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

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

    public function test_future_introduction_availability_is_not_counted_as_a_new_card_yet(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create(['new_cards_per_day' => 20]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'introduction_available_at' => $now->copy()->addDay(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
            'introduction_available_at' => $now,
        ]);

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame(1, $overview['new_count']);
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
}
