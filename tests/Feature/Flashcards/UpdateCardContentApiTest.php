<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

class UpdateCardContentApiTest extends UpdateCardApiTestCase
{
    public function test_it_updates_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => 'arrivederci',
                'back_text' => 'goodbye',
                'card_type' => ' CLOZE ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.card_type', 'cloze');

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'cloze',
        ]);
    }

    public function test_it_updates_structured_content(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.prompt_json.type', 'text')
            ->assertJsonPath('data.prompt_json.text', 'What is ATP?')
            ->assertJsonPath('data.answer_json.type', 'text')
            ->assertJsonPath('data.answer_json.text', 'Cellular energy currency.');

        $card->refresh();

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $card->answer_json);
    }

    public function test_it_clears_structured_content_when_explicit_nulls_are_provided(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => null,
            'answer_json' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $card->refresh();

        $this->assertNull($card->prompt_json);
        $this->assertNull($card->answer_json);
    }
}
