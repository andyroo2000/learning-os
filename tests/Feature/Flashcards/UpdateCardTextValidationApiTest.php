<?php

namespace Tests\Feature\Flashcards;

class UpdateCardTextValidationApiTest extends UpdateCardApiTestCase
{
    public function test_it_rejects_blank_front_text(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '   ',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_text_fields_together(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_non_string_text_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => ['arrivederci'],
            'back_text' => ['goodbye'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }
}
