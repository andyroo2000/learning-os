<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardIdentifierValidationApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_rejects_array_ulid_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'id' => [strtolower((string) Str::ulid())],
            'deck_id' => [strtolower((string) Str::ulid())],
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }
}
