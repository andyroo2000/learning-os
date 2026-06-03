<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('cards', [
            'id',
            'deck_id',
            'front_text',
            'back_text',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_card_can_be_created_with_a_deck(): void
    {
        $deck = Deck::factory()->create();

        $card = Card::factory()->create([
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->assertIsString($card->id);
        $this->assertTrue(Str::isUlid($card->id));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
    }

    public function test_card_belongs_to_a_deck(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $this->assertTrue($card->deck->is($deck));
        $this->assertTrue($deck->cards->contains($card));
    }

    public function test_owner_user_id_fails_when_parent_deck_cannot_be_resolved(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_loaded_deck_has_no_owner(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRelation('deck', new Deck);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_null(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => null,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_zero(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => 0,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_negative(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => -1,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_empty(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => '',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_a_malformed_numeric_string(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => '3abc',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_resolves_soft_deleted_parent_decks(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $this->assertSame($deck->user_id, $card->ownerUserId());
    }

    public function test_owner_user_id_uses_a_selected_owner_attribute(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $queriedCard = Card::query()
            ->select('cards.*')
            ->selectRaw('decks.user_id as owner_user_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereKey($card->id)
            ->sole();

        $this->assertSame($deck->user_id, $queriedCard->ownerUserId());
    }

    public function test_card_can_be_soft_deleted(): void
    {
        $card = Card::factory()->create();

        $card->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_cards_are_soft_deleted_when_their_deck_is_soft_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);

        $deletedDeck = Deck::withTrashed()->findOrFail($deck->id);
        $deletedCard = Card::withTrashed()->findOrFail($card->id);

        $this->assertSame(
            $deletedDeck->deleted_at?->toJSON(),
            $deletedCard->deleted_at?->toJSON(),
        );
    }

    public function test_restoring_a_deck_leaves_cascade_deleted_cards_soft_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();
        $deck->restore();

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'deleted_at' => null,
        ]);
        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_deck_soft_delete_preserves_independently_deleted_cards_original_timestamp(): void
    {
        $deck = Deck::factory()->create();
        $independentlyDeletedCard = Card::factory()->create(['deck_id' => $deck->id]);
        $activeCard = Card::factory()->create(['deck_id' => $deck->id]);

        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        try {
            $independentlyDeletedCard->delete();
            $originalDeletedAt = $independentlyDeletedCard->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:01'));

            $deck->delete();

            $this->assertSoftDeleted('cards', [
                'id' => $activeCard->id,
            ]);

            $this->assertDatabaseHas('cards', [
                'id' => $independentlyDeletedCard->id,
                'deleted_at' => $originalDeletedAt,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_redeleting_a_soft_deleted_deck_does_not_retimestamp_cascade_deleted_cards(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        try {
            $deck->delete();
            $originalDeletedAt = $card->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:01'));

            Deck::withTrashed()->findOrFail($deck->id)->delete();

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => $originalDeletedAt,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cards_are_deleted_when_their_deck_is_force_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->forceDelete();

        $this->assertDatabaseMissing('cards', [
            'id' => $card->id,
        ]);
    }
}
