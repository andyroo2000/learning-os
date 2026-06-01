<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateDeckAction;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Exceptions\DeckConflictException;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CreateDeckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_deck_with_a_name(): void
    {
        $user = User::factory()->create();

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
            ),
        );

        $this->assertTrue(Str::isUlid($deck->id));

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $user->id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);
    }

    public function test_it_creates_a_deck_with_a_description(): void
    {
        $user = User::factory()->create();

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: 'Foundational Italian review cards.',
            ),
        );

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $user->id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                id: strtoupper($id),
            ),
        );

        $this->assertSame(strtolower($id), $deck->id);

        $this->assertDatabaseHas('decks', [
            'id' => strtolower($id),
            'user_id' => $user->id,
            'name' => 'Italian Basics',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $user = User::factory()->create();

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: '  Italian Basics  ',
                description: '  Foundational Italian review cards.  ',
            ),
        );

        $this->assertSame('Italian Basics', $deck->name);
        $this->assertSame('Foundational Italian review cards.', $deck->description);
    }

    public function test_it_stores_blank_description_as_null(): void
    {
        $user = User::factory()->create();

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: '   ',
            ),
        );

        $this->assertNull($deck->description);
    }

    public function test_it_rejects_blank_name(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck name is required.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(userId: $user->id, name: '   '),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck ID must be a valid ULID.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                id: 'not-a-ulid',
            ),
        );
    }

    public function test_it_returns_existing_deck_for_idempotent_retries(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingDeck = Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: '   ',
                id: strtoupper($id),
            ),
        );

        $this->assertTrue($existingDeck->is($deck));
        $this->assertFalse($deck->wasRecentlyCreated);
        $this->assertDatabaseCount('decks', 1);
    }

    public function test_it_returns_existing_deck_when_concurrent_create_wins_the_race(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;

        DB::listen(function (QueryExecuted $query) use (&$inserted, $id, $user): void {
            if ($inserted || ! in_array($id, $query->bindings, true)) {
                return;
            }

            $inserted = true;

            DB::table('decks')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'name' => 'Italian Basics',
                'description' => 'Foundational Italian review cards.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $deck = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: 'Foundational Italian review cards.',
                id: $id,
            ),
        );

        $this->assertTrue($inserted);
        $this->assertSame($id, $deck->id);
        $this->assertFalse($deck->wasRecentlyCreated);
        $this->assertDatabaseCount('decks', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $this->expectException(DeckConflictException::class);
        $this->expectExceptionMessage('Deck ID already exists with different metadata.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Spanish Basics',
                id: $id,
            ),
        );
    }
}
