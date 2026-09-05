<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardTextValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => "  {$deck->id}  ",
                'front_text' => '  ciao  ',
                'back_text' => '  hello  ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello');

        $this->assertDatabaseHas('cards', [
            'id' => $response->json('data.id'),
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'search_text' => 'ciao hello',
        ]);
    }

    public function test_it_rejects_blank_text_inputs_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => '   ',
                'back_text' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseCount('cards', 0);
    }
}
