<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_cards_are_deleted_when_their_deck_is_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $this->assertDatabaseMissing('cards', [
            'id' => $card->id,
        ]);
    }
}
