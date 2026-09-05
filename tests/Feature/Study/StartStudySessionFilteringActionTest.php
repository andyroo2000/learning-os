<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\StartStudyLessonAction;
use App\Domain\Study\Actions\StartStudySessionAction;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Results\StartStudySessionResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionFilteringActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_only_uses_owned_cards_from_active_decks(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeck->delete();
        $this->setDailyNewCardLimit($user, 20);
        $ownedNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $result = app(StartStudyLessonAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSessionCardIds($result, [$ownedNewCard->id]);
        $this->assertSame(0, $result->overview['due_count']);
        $this->assertSame(1, $result->overview['total_cards']);
    }

    public function test_it_filters_due_session_cards_by_deck_id(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $this->setDailyNewCardLimit($user, 20);
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

        $this->assertSessionCardIds($result, [$targetDeckCard->id]);
        $this->assertSame(1, $result->overview['due_count']);
        $this->assertSame(1, $result->overview['total_cards']);
    }

    public function test_it_filters_lesson_cards_by_deck_id_and_keeps_daily_guidance_user_wide(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $this->setDailyNewCardLimit($user, 2);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondTargetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'introduced_at' => $now->copy()->subHour(),
            'due_at' => $now->copy()->addDay(),
        ]);

        $result = app(StartStudyLessonAction::class)->handle(
            userId: $user->id,
            now: $now,
            deckId: $deck->id,
        );

        $this->assertSessionCardIds($result, [$targetDeckCard->id, $secondTargetDeckCard->id]);
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

    private function setDailyNewCardLimit(User $user, int $limit): void
    {
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => $limit,
        ]);
    }

    /**
     * @param  list<string>  $expectedIds
     */
    private function assertSessionCardIds(StartStudySessionResult $result, array $expectedIds): void
    {
        $this->assertSame($expectedIds, $result->cards->pluck('id')->all());
    }
}
