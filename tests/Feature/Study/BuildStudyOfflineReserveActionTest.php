<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\BuildStudyOfflineReserveAction;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class BuildStudyOfflineReserveActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_returns_scheduled_cards_through_the_horizon_and_five_days_of_new_cards(): void
    {
        $now = Carbon::parse('2026-07-25T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create(['new_cards_per_day' => 2]);
        $dueNow = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, ['due_at' => $now]);
        $dueAtHorizon = $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
            'due_at' => $now->copy()->addDays(5),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->addDays(5)->addSecond(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 0,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);
        $newCards = collect(range(1, 12))->map(fn (int $position) => $this->cardWithStudyStatus(
            $deck,
            CardStudyStatus::New,
            ['new_queue_position' => $position],
        ));

        $reserve = app(BuildStudyOfflineReserveAction::class)->handle($user->id, $now);

        $this->assertSame($now->toISOString(), $reserve['generated_at']->toISOString());
        $this->assertSame($now->copy()->addDays(5)->toISOString(), $reserve['horizon_ends_at']->toISOString());
        $this->assertSame(
            [$dueNow->id, $dueAtHorizon->id, ...$newCards->take(10)->pluck('id')->all()],
            $reserve['cards']->pluck('id')->all(),
        );
    }

    public function test_it_excludes_other_users_and_soft_deleted_decks(): void
    {
        $user = User::factory()->create();
        $activeDeck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeck->delete();
        $ownedCard = $this->cardWithStudyStatus($activeDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::New, ['new_queue_position' => 2]);
        $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::New,
            ['new_queue_position' => 3],
        );

        $reserve = app(BuildStudyOfflineReserveAction::class)->handle($user->id);

        $this->assertSame([$ownedCard->id], $reserve['cards']->pluck('id')->all());
    }

    public function test_it_includes_spaced_new_cards_within_but_not_beyond_the_offline_horizon(): void
    {
        $now = Carbon::parse('2026-07-25T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $withinHorizon = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'introduction_available_at' => $now->copy()->addDays(5),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
            'introduction_available_at' => $now->copy()->addDays(5)->addSecond(),
        ]);

        $reserve = app(BuildStudyOfflineReserveAction::class)->handle($user->id, $now);

        $this->assertSame([$withinHorizon->id], $reserve['cards']->pluck('id')->all());
    }

    public function test_it_bounds_the_scheduled_card_payload_for_lapsed_users(): void
    {
        $now = Carbon::parse('2026-07-25T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        Card::factory()
            ->for($deck)
            ->count(BuildStudyOfflineReserveAction::MAX_SCHEDULED_CARDS + 1)
            ->create([
                'study_status' => CardStudyStatus::Review,
                'due_at' => $now->copy()->subMonth(),
            ]);

        $reserve = app(BuildStudyOfflineReserveAction::class)->handle($user->id, $now);

        $this->assertCount(
            BuildStudyOfflineReserveAction::MAX_SCHEDULED_CARDS,
            $reserve['cards'],
        );
    }
}
