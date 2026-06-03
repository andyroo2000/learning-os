<?php

namespace Tests\Unit\Resources\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Http\Resources\Flashcards\CardResource;
use App\Http\Resources\Flashcards\DeckResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashcardResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deck_resource_serializes_deleted_at_for_tombstones(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        $deck->delete();
        $deck = Deck::withTrashed()->findOrFail($deck->id);

        $this->assertNotNull($deck->deleted_at);
        $this->assertSame(
            $deck->deleted_at->toJSON(),
            DeckResource::make($deck)->resolve()['deleted_at'],
        );
    }

    public function test_card_resource_serializes_deleted_at_for_tombstones(): void
    {
        $card = $this->cardFor(User::factory()->create());

        $card->delete();
        $card = Card::withTrashed()->findOrFail($card->id);

        $this->assertNotNull($card->deleted_at);
        $this->assertSame(
            $card->deleted_at->toJSON(),
            CardResource::make($card)->resolve()['deleted_at'],
        );
    }
}
