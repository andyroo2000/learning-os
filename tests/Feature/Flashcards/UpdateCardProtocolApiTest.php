<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;

class UpdateCardProtocolApiTest extends UpdateCardApiTestCase
{
    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_does_not_accept_patch_updates(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertStatus(405);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }
}
