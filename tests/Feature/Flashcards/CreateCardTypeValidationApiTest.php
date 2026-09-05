<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardTypeValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_card_type_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'card_type' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_malformed_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'reverse',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_null_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => null,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_array_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => ['production'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }
}
