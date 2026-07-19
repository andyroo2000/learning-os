<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\SelectDailyAudioPracticeCardsAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SelectDailyAudioPracticeCardsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_empty_selection_when_no_cards_are_eligible(): void
    {
        $user = User::factory()->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($user->id);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(1, $queries, $queries->pluck('query')->implode("\n"));
        $this->assertTrue($result->cards->isEmpty());
        $this->assertSame([
            'totalCandidates' => 0,
            'totalEligible' => 0,
            'selectedCount' => 0,
            'dueCount' => 0,
            'learningCount' => 0,
            'recentMissCount' => 0,
        ], $result->summary);
        $this->assertSame([], $result->clientCardIds());
    }

    public function test_it_selects_only_eligible_cards_owned_through_active_decks(): void
    {
        $now = CarbonImmutable::parse('2026-07-19T12:00:00Z');
        $user = User::factory()->create();
        $activeDeck = Deck::factory()->for($user)->create();
        $deletedDeck = Deck::factory()->for($user)->create();
        $deletedDeck->delete();
        $otherDeck = Deck::factory()->for(User::factory()->create())->create();

        $eligible = $this->card($activeDeck, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $now->subHour(),
        ]);
        $this->card($activeDeck, ['study_status' => CardStudyStatus::Suspended]);
        $this->card($activeDeck, ['study_status' => CardStudyStatus::Buried]);
        $this->card($deletedDeck, ['study_status' => CardStudyStatus::Review]);
        $this->card($otherDeck, ['study_status' => CardStudyStatus::Review]);

        $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($user->id, $now);

        $this->assertSame([$eligible->id], $result->cards->modelKeys());
        $this->assertSame([
            'totalCandidates' => 1,
            'totalEligible' => 1,
            'selectedCount' => 1,
            'dueCount' => 1,
            'learningCount' => 0,
            'recentMissCount' => 0,
        ], $result->summary);
    }

    public function test_it_reserves_thirty_percent_for_new_or_recently_introduced_cards(): void
    {
        $now = CarbonImmutable::parse('2026-07-19T12:00:00Z');
        $deck = Deck::factory()->for(User::factory()->create())->create();

        $overdue = collect(range(1, 30))->map(fn (): Card => $this->card($deck, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $now->subDays(30),
            'introduced_at' => $now->subDays(100),
            'last_reviewed_at' => $now->subDays(30),
            'source_lapses' => 4,
        ]));
        $newCards = collect(range(1, 12))->map(fn (): Card => $this->card($deck, [
            'study_status' => CardStudyStatus::New,
            'due_at' => null,
            'introduced_at' => null,
        ]));

        $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($deck->user_id, $now);

        $selectedNewIds = $result->cards
            ->filter(fn (Card $card): bool => $card->study_status === CardStudyStatus::New)
            ->modelKeys();

        $this->assertCount(9, $selectedNewIds);
        $this->assertEmpty(array_diff($selectedNewIds, $newCards->pluck('id')->all()));
        $this->assertCount(21, array_intersect(
            $result->cards->modelKeys(),
            $overdue->pluck('id')->all(),
        ));
        $this->assertSame(30, $result->summary['selectedCount']);
        $this->assertSame(21, $result->summary['dueCount']);
        $this->assertSame(21, $result->summary['recentMissCount']);
    }

    public function test_scoring_prioritizes_due_relearning_cards_with_lapses(): void
    {
        $now = CarbonImmutable::parse('2026-07-19T12:00:00Z');
        $deck = Deck::factory()->for(User::factory()->create())->create();
        $plain = $this->card($deck, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $now->addDay(),
            'introduced_at' => $now->subDays(100),
        ]);
        $priority = $this->card($deck, [
            'study_status' => CardStudyStatus::Relearning,
            'due_at' => $now->subMinute(),
            'introduced_at' => $now->subDays(100),
            'source_lapses' => 2,
        ]);
        CardReviewEvent::factory()->count(25)->for($plain, 'card')->create([
            'reviewed_at' => $now->subDays(30),
        ]);

        $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($deck->user_id, $now);

        $this->assertSame($priority->id, $result->cards->first()->id);
        $this->assertSame(1, $result->summary['learningCount']);
        $this->assertSame(1, $result->summary['recentMissCount']);
    }

    public function test_scoring_prefers_an_unreviewed_card_when_other_state_is_equal(): void
    {
        $now = CarbonImmutable::parse('2026-07-19T12:00:00Z');
        $deck = Deck::factory()->for(User::factory()->create())->create();
        $reviewed = $this->card($deck, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $now->addDay(),
            'introduced_at' => $now->subDays(100),
            'last_reviewed_at' => $now->subDays(30),
        ]);
        $unreviewed = $this->card($deck, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $now->addDay(),
            'introduced_at' => $now->subDays(100),
            'last_reviewed_at' => $now->subDays(30),
        ]);
        CardReviewEvent::factory()->count(25)->for($reviewed, 'card')->create([
            'reviewed_at' => $now->subDays(30),
        ]);
        DB::table('cards')
            ->whereIn('id', [$reviewed->id, $unreviewed->id])
            ->update(['updated_at' => $now->subDays(10)]);

        $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($deck->user_id, $now);

        $this->assertSame($unreviewed->id, $result->cards->first()->id);
    }

    public function test_it_bounds_the_candidate_pool_and_uses_two_queries(): void
    {
        $now = CarbonImmutable::parse('2026-07-19T12:00:00Z');
        $deck = Deck::factory()->for(User::factory()->create())->create();

        foreach (range(1, 85) as $position) {
            $card = $this->card($deck, [
                'study_status' => CardStudyStatus::Review,
                'due_at' => $now->subDays(10 - $position),
            ]);
            CardReviewEvent::factory()->for($card, 'card')->create();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($deck->user_id, $now);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(2, $queries, $queries->pluck('query')->implode("\n"));
        $this->assertSame(SelectDailyAudioPracticeCardsAction::DEFAULT_CANDIDATE_POOL_SIZE, $result->summary['totalCandidates']);
        $this->assertSame(SelectDailyAudioPracticeCardsAction::DEFAULT_SELECTION_LIMIT, $result->summary['selectedCount']);
        $this->assertStringContainsString(
            'CASE WHEN cards.due_at IS NULL THEN 1 ELSE 0 END',
            $queries->first()['query'],
        );
        $this->assertStringNotContainsString(
            'cards.*',
            $queries->first()['query'],
        );
        $this->assertStringNotContainsString(
            'scheduler_state',
            $queries->first()['query'],
        );
    }

    public function test_it_returns_client_identifiers_for_copied_and_native_cards(): void
    {
        $deck = Deck::factory()->for(User::factory()->create())->create();
        $copied = $this->card($deck, [
            'convolab_id' => '33cb3d35-8566-4dd5-aebe-af1725c3d18a',
            'study_status' => CardStudyStatus::New,
        ]);
        $native = $this->card($deck, ['study_status' => CardStudyStatus::New]);

        $result = app(SelectDailyAudioPracticeCardsAction::class)->handle($deck->user_id);

        $this->assertContains($copied->convolab_id, $result->clientCardIds());
        $this->assertContains($native->id, $result->clientCardIds());
        $this->assertNotContains($copied->id, $result->clientCardIds());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(Deck $deck, array $attributes): Card
    {
        return Card::factory()
            ->for($deck)
            ->create([
                'prompt_json' => ['cueText' => fake()->word()],
                'answer_json' => ['meaning' => fake()->word()],
                ...$attributes,
            ]);
    }
}
