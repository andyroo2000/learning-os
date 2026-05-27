<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateDeckAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CreateDeckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_deck_with_a_name(): void
    {
        $deck = app(CreateDeckAction::class)->handle(
            name: 'Italian Basics',
        );

        $this->assertTrue(Str::isUlid($deck->id));

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);
    }

    public function test_it_creates_a_deck_with_a_description(): void
    {
        $deck = app(CreateDeckAction::class)->handle(
            name: 'Italian Basics',
            description: 'Foundational Italian review cards.',
        );

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $id = strtolower((string) Str::ulid());

        $deck = app(CreateDeckAction::class)->handle(
            name: 'Italian Basics',
            id: $id,
        );

        $this->assertSame($id, $deck->id);

        $this->assertDatabaseHas('decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $deck = app(CreateDeckAction::class)->handle(
            name: '  Italian Basics  ',
            description: '  Foundational Italian review cards.  ',
        );

        $this->assertSame('Italian Basics', $deck->name);
        $this->assertSame('Foundational Italian review cards.', $deck->description);
    }

    public function test_it_stores_blank_description_as_null(): void
    {
        $deck = app(CreateDeckAction::class)->handle(
            name: 'Italian Basics',
            description: '   ',
        );

        $this->assertNull($deck->description);
    }

    public function test_it_rejects_blank_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck name is required.');

        app(CreateDeckAction::class)->handle(name: '   ');
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck ID must be a valid ULID.');

        app(CreateDeckAction::class)->handle(
            name: 'Italian Basics',
            id: 'not-a-ulid',
        );
    }
}
