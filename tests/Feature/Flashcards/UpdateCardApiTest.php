<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.deck_id', $card->deck_id)
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye')
            ->assertJsonMissingPath('data.media_assets')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'deck_id',
                    'front_text',
                    'back_text',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", [
            'front_text' => '  arrivederci  ',
            'back_text' => '  goodbye  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye');
    }

    public function test_it_rejects_invalid_input(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", [
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

        $response = $this->patchJson("/api/cards/{$card->id}", [
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

    public function test_it_rejects_missing_required_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", []);

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

        $missingFrontText = $this->patchJson("/api/cards/{$card->id}", [
            'back_text' => 'goodbye',
        ]);

        $missingFrontText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $missingBackText = $this->patchJson("/api/cards/{$card->id}", [
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

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->patchJson("/api/cards/{$otherCard->id}", [
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

        $response = $this->patchJson("/api/cards/{$otherCard->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['front_text', 'back_text']);
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();
        $missingCardId = strtolower((string) Str::ulid());

        $response = $this->patchJson("/api/cards/{$missingCardId}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->patchJson("/api/cards/{$card->id}", [
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
}
