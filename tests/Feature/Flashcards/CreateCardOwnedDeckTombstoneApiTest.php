<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardOwnedDeckTombstoneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_gone_for_idempotent_retries_after_the_deck_is_soft_deleted(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $deck->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_returns_gone_when_the_deck_is_soft_deleted_but_the_card_row_survives(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        // Bypass the model cascade so the card row stays active while the deck is tombstoned.
        DB::table('decks')
            ->where('id', $deck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted deck.')
            ->assertJsonPath('reason', 'deck_deleted');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_rejects_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $deck->delete();

        $response = $this->postJson('/api/cards', [
            'id' => strtolower((string) Str::ulid()),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }
}
