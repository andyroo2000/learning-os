<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

class UpdateCardCardTypeValidationApiTest extends UpdateCardApiTestCase
{
    public function test_it_rejects_blank_card_type_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => 'arrivederci',
                'back_text' => 'goodbye',
                'card_type' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_malformed_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'reverse',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_null_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => null,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_array_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => ['cloze'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }
}
