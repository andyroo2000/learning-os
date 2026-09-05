<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardClientUlidApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = (string) Str::ulid();

        $response = $this->postJson('/api/cards', [
            'id' => strtoupper($id),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', strtolower($id));

        $this->assertDatabaseHas('cards', [
            'id' => strtolower($id),
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_normalizes_padded_uppercase_client_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        // TrimStrings processes JSON bodies too; disable it to prove StoreCardRequest normalizes independently.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'id' => '  '.strtoupper($id).'  ',
                'deck_id' => '  '.strtoupper($deck->id).'  ',
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_trims_client_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $lowercaseId = strtolower((string) Str::ulid());

        // TrimStrings processes JSON bodies too; disable it to prove StoreCardRequest normalizes independently.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'id' => "  {$lowercaseId}  ",
                'deck_id' => "  {$deck->id}  ",
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $lowercaseId)
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $lowercaseId,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_lowercases_client_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        // TrimStrings processes JSON bodies too; disable it to prove StoreCardRequest normalizes independently.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'id' => strtoupper($id),
                'deck_id' => strtoupper($deck->id),
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }
}
