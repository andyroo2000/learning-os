<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_card(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello')
            ->assertJsonMissingPath('data.media_assets')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'deck_id',
                    'front_text',
                    'back_text',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('cards', [
            'id' => $response->json('data.id'),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => "  {$deck->id}  ",
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello');
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'id' => 'not-a-ulid',
            'deck_id' => 'also-not-a-ulid',
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'deck_id', 'front_text', 'back_text']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_missing_deck(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $response = $this->postJson('/api/cards', [
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $deck = Deck::factory()->create();

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('cards', 0);
    }
}
