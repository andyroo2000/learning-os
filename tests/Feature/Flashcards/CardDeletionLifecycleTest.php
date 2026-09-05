<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CardDeletionLifecycleTest extends TestCase
{
    use RefreshDatabase;

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
