<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardConcurrencyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_card_when_concurrent_create_wins_the_race(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;

        $createCard = new CreateCardAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $deck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $deck->id,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        );

        $result = $createCard->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $card = $result->card;

        $this->assertTrue($inserted);
        $this->assertSame($id, $card->id);
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rethrows_the_unique_exception_when_the_race_winner_disappears_before_refetch(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $deleted = false;

        $createCard = new CreateCardAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $deck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $deck->id,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            afterClientIdUniqueConflict: function (CreateCardData $data) use (&$deleted): void {
                $deleted = DB::table('cards')->where('id', $data->id)->delete() === 1;
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                    id: $id,
                ),
            );

            $this->fail('The original unique constraint exception was not rethrown.');
        } catch (QueryException) {
            $this->assertTrue($inserted);
            $this->assertTrue($deleted);
            $this->assertDatabaseCount('cards', 0);
        }
    }
}
