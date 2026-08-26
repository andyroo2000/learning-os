<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Services\SelectNewStudyCards;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SelectNewStudyCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_weights_mix_lanes_three_one_one_without_reordering_within_a_lane(): void
    {
        Carbon::setTestNow('2026-08-26T12:00:00Z');
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $standardOne = $this->card($deck, 10, CardSelectionPolicy::Standard);
        $standardTwo = $this->card($deck, 20, CardSelectionPolicy::Standard);
        $standardThree = $this->card($deck, 30, CardSelectionPolicy::Standard);
        $lesson = $this->card($deck, 40, CardSelectionPolicy::ReviewSoon, now()->addWeek());
        $waniKani = $this->card($deck, 50, CardSelectionPolicy::Sprinkled, now()->addWeek());

        $selected = app(SelectNewStudyCards::class)->handle(
            $this->baseQuery($user->id),
            $user->id,
            5,
            now(),
        );

        $this->assertSame([
            $standardOne->id,
            $lesson->id,
            $waniKani->id,
            $standardTwo->id,
            $standardThree->id,
        ], $selected->pluck('id')->all());
    }

    public function test_empty_and_zero_weight_lanes_redistribute_to_available_cards(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        StudySettings::factory()->for($user)->create([
            'standard_lane_weight' => 1,
            'lesson_followup_lane_weight' => 0,
            'wanikani_lane_weight' => 4,
        ]);
        $standardOne = $this->card($deck, 1, CardSelectionPolicy::Standard);
        $standardTwo = $this->card($deck, 2, CardSelectionPolicy::Standard);
        $lesson = $this->card($deck, 3, CardSelectionPolicy::ReviewSoon, now()->addWeek());

        $selected = app(SelectNewStudyCards::class)->handle(
            $this->baseQuery($user->id),
            $user->id,
            3,
            now(),
        );

        $this->assertSame([$standardOne->id, $standardTwo->id], $selected->pluck('id')->all());
        $this->assertNotContains($lesson->id, $selected->pluck('id')->all());
    }

    public function test_expired_priority_returns_to_standard_and_future_availability_is_excluded(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $expired = $this->card($deck, 1, CardSelectionPolicy::Sprinkled, now()->subSecond());
        $future = $this->card($deck, 2, CardSelectionPolicy::Sprinkled, now()->addWeek());
        $future->introduction_available_at = now()->addDay();
        $future->save();
        $lockedSibling = $this->card($deck, 3, CardSelectionPolicy::Sprinkled);

        $selected = app(SelectNewStudyCards::class)->handle(
            $this->baseQuery($user->id),
            $user->id,
            2,
            now(),
        );

        $this->assertSame([$expired->id], $selected->pluck('id')->all());
        $this->assertNotContains($lockedSibling->id, $selected->pluck('id')->all());
    }

    public function test_introductions_earlier_in_the_study_day_advance_the_fairness_cursor(): void
    {
        Carbon::setTestNow('2026-08-26T16:00:00Z');
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $this->introducedCard($deck, CardSelectionPolicy::Standard);
        $this->introducedCard($deck, CardSelectionPolicy::ReviewSoon);
        $standard = $this->card($deck, 10, CardSelectionPolicy::Standard);
        $waniKani = $this->card($deck, 20, CardSelectionPolicy::Sprinkled, now()->addWeek());

        $selected = app(SelectNewStudyCards::class)->handle(
            $this->baseQuery($user->id),
            $user->id,
            2,
            now(),
            'America/New_York',
        );

        $this->assertSame([$waniKani->id, $standard->id], $selected->pluck('id')->all());
    }

    private function card(
        Deck $deck,
        int $position,
        CardSelectionPolicy $policy,
        ?Carbon $priorityUntil = null,
    ): Card {
        return Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::New,
            'new_queue_position' => $position,
            'selection_policy' => $policy,
            'priority_until' => $priorityUntil,
        ]);
    }

    private function introducedCard(Deck $deck, CardSelectionPolicy $policy): Card
    {
        return Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Learning,
            'new_queue_position' => null,
            'selection_policy' => $policy,
            'introduced_at' => now()->subHour(),
        ]);
    }

    private function baseQuery(int $userId): Builder
    {
        return Card::query()
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereProgressionAvailable();
    }
}
