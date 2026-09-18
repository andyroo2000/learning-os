<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyCardClozePresentationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_browse_and_edit_return_a_masked_reading_without_mutating_imported_spacing(): void
    {
        $user = $this->signIn();
        $prompt = ['clozeText' => 'この本はとても{{c1::役に立ちます}}。'];
        $answer = [
            'restoredText' => 'この本はとても役に立ちます。',
            'restoredTextReading' => 'この 本[ほん]は とても 役[やく]に 立[た]ちます。',
            'meaning' => 'This book is very useful.',
        ];
        $card = Card::factory()->for($this->deckFor($user))->create([
            'card_type' => CardType::Cloze,
            'prompt_json' => $prompt,
            'answer_json' => $answer,
        ]);

        $this->getJson("/api/study/browser/{$card->id}")->assertOk()
            ->assertJsonPath('cards.0.presentation.front.text', 'この本はとても[...]。')
            ->assertJsonPath('cards.0.presentation.front.ruby', 'この 本[ほん]は とても [...]。')
            ->assertJsonPath('cards.0.presentation.answer.ruby', $answer['restoredTextReading']);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => $prompt,
            'answer' => [...$answer, 'notes' => 'A useful book.'],
        ])->assertOk()
            ->assertJsonPath('presentation.front.ruby', 'この 本[ほん]は とても [...]。')
            ->assertJsonPath('answer.restoredTextReading', $answer['restoredTextReading']);

        $this->assertSame($answer['restoredTextReading'], $card->refresh()->answer_json['restoredTextReading']);
        $this->assertSame($prompt, $card->prompt_json);
    }
}
