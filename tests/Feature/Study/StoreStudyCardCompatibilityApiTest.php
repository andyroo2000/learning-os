<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StoreStudyCardCompatibilityApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_creates_a_manual_study_card_in_the_default_study_deck(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => [
                'cueText' => '会社',
                'cueReading' => 'かいしゃ',
            ],
            'answer' => [
                'expression' => '会社',
                'meaning' => 'company',
            ],
            'study_status' => 'review',
            'new_queue_position' => 99,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('cardType', 'recognition')
            ->assertJsonPath('noteId', null)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('prompt.cueReading', 'かいしゃ')
            ->assertJsonPath('answer.expression', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('state.queueState', 'new')
            ->assertJsonPath('state.source.noteId', null)
            ->assertJsonPath('answerAudioSource', 'missing');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $deck = Deck::query()->sole();
        $this->assertSame($user->id, $deck->user_id);
        $this->assertSame(ResolveManualStudyDeckAction::DEFAULT_DECK_NAME, $deck->name);
        $this->assertSame(ResolveManualStudyDeckAction::DEFAULT_DECK_DESCRIPTION, $deck->description);
        $this->assertTrue($deck->is_manual_study_deck);

        $card = Card::query()->sole();
        $this->assertSame($deck->id, $card->deck_id);
        $this->assertSame('会社', $card->front_text);
        $this->assertSame('会社', $card->back_text);
        $this->assertSame(CardType::Recognition, $card->card_type);
        $this->assertSame(['cueText' => '会社', 'cueReading' => 'かいしゃ'], $card->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $card->answer_json);
        $this->assertSame('会社 会社 会社 かいしゃ 会社 company', $card->search_text);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('deck', $entries[0]->resource_type);
        $this->assertSame($deck->id, $entries[0]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[0]->operation);
        $cardEntry = $this->cardSyncEntryFor($card);
        $this->assertSame(SyncFeedOperation::Create, $cardEntry->operation);
        $this->assertSame(CardSyncPayload::fromCard($card), $cardEntry->payload);
    }

    public function test_it_creates_a_manual_study_card_with_variant_metadata(): void
    {
        $user = $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/cards', [
                'creationKind' => ' text-recognition ',
                'cardType' => ' retired-client-value ',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantGroupId' => ' vocab-group-1 ',
                'variantSentenceId' => ' sentence-1 ',
                'variantKind' => ' SENTENCE_AUDIO_RECOGNITION ',
                'variantStage' => ' +2 ',
                'variantStatus' => ' AVAILABLE ',
                'variantUnlockedAt' => '2026-06-04T14:15:30.987654+05:30',
            ])
            ->assertCreated()
            ->assertJsonPath('cardType', CardType::Recognition->value)
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', 'sentence-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Available->value)
            // The storage column is second-precision, so fractional input is normalized away.
            ->assertJsonPath('variantUnlockedAt', '2026-06-04T08:45:30.000Z');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card = Card::query()->sole();
        $this->assertSame($user->id, $card->ownerUserId());
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertSame('sentence-1', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $card->variant_kind);
        $this->assertSame(2, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $card->variant_unlocked_at?->toJSON());

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $entries);
        $cardEntry = $this->cardSyncEntryFor($card);
        $this->assertSame(CardSyncPayload::fromCard($card), $cardEntry->payload);
    }

    public function test_it_accepts_unsigned_string_variant_stage_without_trim_strings_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/cards', [
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantStage' => ' 2 ',
            ])
            ->assertCreated()
            ->assertJsonPath('variantStage', 2);

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card = Card::query()->sole();
        $this->assertSame(2, $card->variant_stage);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $entries);
        $cardEntry = $this->cardSyncEntryFor($card);
        $this->assertSame(CardSyncPayload::fromCard($card), $cardEntry->payload);
    }

    public function test_it_treats_blank_manual_card_variant_metadata_as_absent(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/cards', [
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantGroupId' => '   ',
                'variantSentenceId' => "\t",
                'variantKind' => '   ',
                'variantStage' => null,
                'variantStatus' => "\n",
                'variantUnlockedAt' => '   ',
            ])
            ->assertCreated()
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card = Card::query()->sole();
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);
    }

    public function test_it_accepts_partial_manual_card_variant_metadata(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantGroupId' => 'vocab-group-1',
        ])
            ->assertCreated()
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card = Card::query()->sole();
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $entries);
        $cardEntry = $this->cardSyncEntryFor($card);
        $this->assertSame(CardSyncPayload::fromCard($card), $cardEntry->payload);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertUnauthorized();
    }

    private function cardSyncEntryFor(Card $card): SyncFeedEntry
    {
        return SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();
    }
}
