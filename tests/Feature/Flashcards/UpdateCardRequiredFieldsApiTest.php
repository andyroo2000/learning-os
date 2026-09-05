<?php

namespace Tests\Feature\Flashcards;

class UpdateCardRequiredFieldsApiTest extends UpdateCardApiTestCase
{
    public function test_it_rejects_missing_required_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_partial_updates(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $missingFrontText = $this->putJson("/api/cards/{$card->id}", [
            'back_text' => 'goodbye',
        ]);

        $missingFrontText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $missingBackText = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
        ]);

        $missingBackText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }
}
