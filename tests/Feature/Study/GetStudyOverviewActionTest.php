<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
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

    public function test_it_rejects_invalid_time_zones_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study time_zone must be a valid IANA timezone.');

        app(GetStudyOverviewAction::class)->handle(
            userId: User::factory()->create()->id,
            timeZone: 'Not/A_Zone',
        );
    }
}
