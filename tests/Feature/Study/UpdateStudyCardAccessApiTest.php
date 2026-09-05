<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardAccessApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_normalizes_route_id_and_payload_text_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'old front',
            'back_text' => 'old back',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson('/api/study/cards/'.strtoupper($card->id), [
                'prompt' => ['cueText' => '  会社  '],
                'answer' => ['meaning' => '  company  '],
            ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', '  会社  ')
            ->assertJsonPath('answer.meaning', '  company  ');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card->refresh();

        $this->assertSame('会社', $card->front_text);
        $this->assertSame('company', $card->back_text);
        $this->assertSame(['cueText' => '  会社  '], $card->prompt_json);
        $this->assertSame(['meaning' => '  company  '], $card->answer_json);
    }

    public function test_it_returns_not_found_for_missing_deleted_or_cross_user_cards(): void
    {
        $user = $this->signIn();
        $deletedCard = Card::factory()->for($this->deckFor($user))->create();
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();
        $otherUserCard = Card::factory()->for($this->deckFor(User::factory()->create()))->create();

        $deletedCard->delete();
        $deletedDeck->delete();

        $payload = [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ];

        $this->patchJson("/api/study/cards/{$deletedCard->id}", $payload)->assertNotFound();
        $this->patchJson("/api/study/cards/{$deletedDeckCard->id}", $payload)->assertNotFound();
        $this->patchJson("/api/study/cards/{$otherUserCard->id}", $payload)->assertNotFound();
        $this->patchJson('/api/study/cards/01HX0000000000000000000000', $payload)->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertUnauthorized();
    }
}
