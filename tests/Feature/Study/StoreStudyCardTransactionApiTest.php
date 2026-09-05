<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class StoreStudyCardTransactionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_locks_the_owner_before_reusing_the_existing_default_study_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $resolvedDeck = DB::transaction(
                fn (): Deck => app(ResolveManualStudyDeckAction::class)->handle($user->id),
            );

            $userQueries = collect(DB::getQueryLog())
                ->filter(fn (array $query): bool => preg_match('/\bfrom\s+(?:"users"|`users`|users)(?![a-z0-9_])/', strtolower($query['query'])) === 1)
                ->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame($deck->id, $resolvedDeck->id);
        $this->assertSame(1, $userQueries);
    }

    public function test_manual_deck_resolution_requires_an_outer_transaction(): void
    {
        DB::partialMock()
            ->shouldReceive('transactionLevel')
            ->once()
            ->andReturn(0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Manual study deck resolution must run inside an outer transaction.');

        app(ResolveManualStudyDeckAction::class)->handle(1);
    }

    public function test_it_does_not_reuse_a_regular_deck_with_the_default_name(): void
    {
        $user = $this->signIn();
        $regularDeck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => false,
        ]);

        $this->postJson('/api/study/cards', [
            'cardType' => 'production',
            'prompt' => ['cueText' => 'company'],
            'answer' => ['meaning' => '会社'],
        ])->assertCreated();

        $manualDeck = Deck::query()->where('is_manual_study_deck', true)->sole();
        $this->assertSame(2, Deck::query()->count());
        $this->assertFalse($regularDeck->refresh()->is_manual_study_deck);
        $this->assertSame($manualDeck->id, Card::query()->sole()->deck_id);
    }

    public function test_it_creates_the_card_inside_the_manual_deck_transaction(): void
    {
        $this->signIn();
        $transactionLevelDuringCardSync = 0;

        $this->app->bind(CreateCardAction::class, function () use (&$transactionLevelDuringCardSync): CreateCardAction {
            $recordSyncFeedEntry = new class($transactionLevelDuringCardSync) extends RecordSyncFeedEntryAction
            {
                private int $transactionLevelDuringCardSync;

                public function __construct(int &$transactionLevelDuringCardSync)
                {
                    $this->transactionLevelDuringCardSync = &$transactionLevelDuringCardSync;
                }

                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    if ($data->resourceType === 'card') {
                        $this->transactionLevelDuringCardSync = DB::transactionLevel();
                    }

                    return app(RecordSyncFeedEntryAction::class)->handle($data);
                }
            };

            return new CreateCardAction($recordSyncFeedEntry);
        });

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])->assertCreated();

        $this->assertGreaterThanOrEqual(2, $transactionLevelDuringCardSync);
    }

    public function test_it_starts_a_fresh_default_study_deck_when_the_previous_one_was_deleted(): void
    {
        $user = $this->signIn();
        $deletedDeck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);

        app(DeleteDeckAction::class)->handle($deletedDeck);

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])->assertCreated();

        $activeDeck = Deck::query()->sole();
        $this->assertNotSame($deletedDeck->id, $activeDeck->id);
        $this->assertTrue($activeDeck->is_manual_study_deck);
        $this->assertSame(2, Deck::withTrashed()->count());
        $this->assertTrue(Deck::withTrashed()->findOrFail($deletedDeck->id)->trashed());
        $this->assertSame($activeDeck->id, Card::query()->sole()->deck_id);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(3, $entries);
        $this->assertSame($deletedDeck->id, $entries[0]->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entries[0]->operation);
        $this->assertSame($activeDeck->id, $entries[1]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[1]->operation);
        $this->assertSame('card', $entries[2]->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $entries[2]->operation);
    }
}
