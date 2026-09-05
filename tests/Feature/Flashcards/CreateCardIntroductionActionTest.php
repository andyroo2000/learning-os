<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class CreateCardIntroductionActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_persists_server_owned_introduction_metadata_and_replays_it_idempotently(): void
    {
        $deck = Deck::factory()->create();
        $cohort = new CardIntroductionCohort;
        $cohort->user_id = $deck->user_id;
        $cohort->source_kind = 'wanikani';
        $cohort->source_reference = '123';
        $cohort->save();
        $cardId = strtolower((string) Str::ulid());
        $priorityUntil = now()->startOfSecond()->addWeek();
        $data = CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: '会社',
            backText: 'company',
            id: $cardId,
            introductionCohortId: $cohort->id,
            selectionPolicy: CardSelectionPolicy::Sprinkled,
            priorityUntil: $priorityUntil,
        );

        $first = app(CreateCardAction::class)->handle($data);
        $second = app(CreateCardAction::class)->handle($data);
        $card = $first->card->refresh();

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($cohort->id, $card->introduction_cohort_id);
        $this->assertSame(CardSelectionPolicy::Sprinkled, $card->selection_policy);
        $this->assertTrue($card->priority_until->equalTo($priorityUntil));
        $entry = $this->assertCardSyncPayloadRecorded($card, SyncFeedOperation::Create);
        $this->assertSame($cohort->id, $entry->payload['introduction_cohort_id']);
        $this->assertSame('sprinkled', $entry->payload['selection_policy']);
        $this->assertSame($priorityUntil->toJSON(), $entry->payload['priority_until']);
    }

    public function test_it_rejects_an_introduction_cohort_owned_by_another_user(): void
    {
        $deck = Deck::factory()->create();
        $cohort = new CardIntroductionCohort;
        $cohort->user_id = User::factory()->create()->id;
        $cohort->source_kind = 'wanikani';
        $cohort->source_reference = '123';
        $cohort->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card introduction cohort must belong to the card owner.');

        app(CreateCardAction::class)->handle(CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: '会社',
            backText: 'company',
            introductionCohortId: $cohort->id,
            selectionPolicy: CardSelectionPolicy::Sprinkled,
        ));
    }
}
