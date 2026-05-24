<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeckTest extends TestCase
{
    use RefreshDatabase;

    public function test_decks_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('decks', [
            'id',
            'name',
            'description',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_deck_can_be_created_with_a_factory(): void
    {
        $deck = Deck::factory()->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_description_is_optional(): void
    {
        $deck = Deck::factory()->create([
            'description' => null,
        ]);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'description' => null,
        ]);
    }
}
