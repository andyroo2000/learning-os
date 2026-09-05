<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StoreStudyCardTypeApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_derives_card_type_from_creation_kind_when_client_state_is_stale(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/study/cards', [
            'creationKind' => 'production-image',
            'cardType' => 'retired-card-type',
            'prompt' => ['cueText' => 'company'],
            'answer' => ['expression' => '会社', 'meaning' => 'company'],
        ])
            ->assertCreated()
            ->assertJsonPath('cardType', 'production');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $this->assertSame(CardType::Production, Card::query()->sole()->card_type);
    }

    public function test_it_derives_card_type_from_creation_kind_without_card_type(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/study/cards', [
            'creationKind' => 'cloze',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertCreated()
            ->assertJsonPath('cardType', 'cloze');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $this->assertSame(CardType::Cloze, Card::query()->sole()->card_type);
    }

    public function test_it_normalizes_card_type_and_payload_text_without_trim_strings_middleware(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/cards', [
                'id' => ' '.strtoupper($id).' ',
                'cardType' => ' PRODUCTION ',
                'prompt' => ['cueText' => '  会社  '],
                'answer' => ['meaning' => '  company  '],
            ])
            ->assertCreated()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('cardType', 'production')
            ->assertJsonPath('prompt.cueText', '  会社  ')
            ->assertJsonPath('answer.meaning', '  company  ');

        $card = Card::query()->sole();
        $this->assertSame($id, $card->id);
        $this->assertSame('会社', $card->front_text);
        $this->assertSame('company', $card->back_text);
        $this->assertSame(['cueText' => '  会社  '], $card->prompt_json);
        $this->assertSame(['meaning' => '  company  '], $card->answer_json);
    }
}
