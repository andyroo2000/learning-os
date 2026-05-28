<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckTest extends TestCase
{
    use RefreshDatabase;

    public function test_decks_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('decks', [
            'id',
            'user_id',
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

        $this->assertIsString($deck->id);
        $this->assertTrue(Str::isUlid($deck->id));

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $deck->user_id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_deck_belongs_to_a_user(): void
    {
        $deck = Deck::factory()->create();

        $this->assertSame($deck->user_id, $deck->user->id);
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
