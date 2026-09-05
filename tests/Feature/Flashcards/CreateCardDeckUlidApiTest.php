<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardDeckUlidApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_uppercase_deck_ids(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => strtoupper($deck->id),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $response->json('data.id'),
            'deck_id' => $deck->id,
        ]);
    }
}
