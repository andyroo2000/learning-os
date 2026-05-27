<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateDeckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_deck(): void
    {
        $response = $this->postJson('/api/decks', [
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Italian Basics')
            ->assertJsonPath('data.description', 'Foundational Italian review cards.')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('decks', [
            'id' => $response->json('data.id'),
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);
    }

    public function test_it_normalizes_optional_description(): void
    {
        $response = $this->postJson('/api/decks', [
            'name' => '  Italian Basics  ',
            'description' => '   ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Italian Basics')
            ->assertJsonPath('data.description', null);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $response = $this->postJson('/api/decks', [
            'id' => 'not-a-ulid',
            'name' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'name']);

        $this->assertDatabaseCount('decks', 0);
    }
}
