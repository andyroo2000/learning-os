<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardCrossUserTombstoneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hides_cross_user_deck_deleted_tombstones(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        // Bypass the model cascade so the card row stays active while the deck is tombstoned.
        DB::table('decks')
            ->where('id', $otherDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');
    }

    public function test_it_hides_cross_user_card_deleted_tombstones_when_the_deck_is_also_deleted(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        DB::table('decks')
            ->where('id', $otherDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');
    }

    public function test_it_hides_idempotent_retries_for_other_users_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
        ]);
        $this->assertDatabaseMissing('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_hides_idempotent_retries_for_other_users_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
        ]);
    }

    public function test_it_hides_cross_user_conflicts_when_concurrent_create_wins_the_race(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $caughtUniqueConflict = false;

        $createCard = new CreateCardAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $otherDeck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $otherDeck->id,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            afterClientIdUniqueConflict: function () use (&$caughtUniqueConflict): void {
                $caughtUniqueConflict = true;
            },
        );

        $this->app->instance(CreateCardAction::class, $createCard);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');

        $this->assertTrue($inserted);
        $this->assertTrue($caughtUniqueConflict);
        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
        ]);
    }
}
