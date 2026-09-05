<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Support\Str;

class UpdateCardVisibilityApiTest extends UpdateCardApiTestCase
{
    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$otherCard->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $otherCard->id,
            'front_text' => $otherCard->front_text,
            'back_text' => $otherCard->back_text,
        ]);
    }

    public function test_it_authorizes_before_validating_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$otherCard->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['front_text', 'back_text']);
    }

    public function test_it_returns_not_found_for_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();

        $deck->delete();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();
        $missingCardId = (string) Str::ulid();

        $response = $this->putJson("/api/cards/{$missingCardId}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_card_id(): void
    {
        $this->signIn();

        $response = $this->putJson('/api/cards/not-a-ulid', [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }
}
