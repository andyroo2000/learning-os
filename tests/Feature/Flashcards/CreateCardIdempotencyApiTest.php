<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardIdempotencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_card_for_idempotent_retries(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $payload = [
            'id' => strtoupper($id),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ];

        $firstResponse = $this->postJson('/api/cards', $payload);
        $secondResponse = $this->postJson('/api/cards', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $this->assertDatabaseCount('cards', 1);
    }
}
