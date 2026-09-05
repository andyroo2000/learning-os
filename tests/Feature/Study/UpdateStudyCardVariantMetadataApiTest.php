<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardVariantMetadataApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_updates_variant_metadata_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceTextRecognition,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/study/cards/{$card->id}", [
                'prompt' => ['cueText' => '  会社  '],
                'answer' => ['meaning' => '  company  '],
                'variantGroupId' => ' vocab-group-1 ',
                'variantSentenceId' => ' sentence-1 ',
                'variantKind' => ' SENTENCE_CLOZE ',
                'variantStage' => ' +3 ',
                'variantStatus' => ' AVAILABLE ',
                'variantUnlockedAt' => '2026-06-04T14:15:30+05:30',
            ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', '  会社  ')
            ->assertJsonPath('answer.meaning', '  company  ')
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', 'sentence-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('variantStage', 3)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Available->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-04T08:45:30.000Z');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card->refresh();
        $this->assertSame('会社', $card->front_text);
        $this->assertSame('company', $card->back_text);
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertSame('sentence-1', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $card->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame(CardSyncPayload::fromCard($card), $entry->payload);
    }

    public function test_it_clears_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/study/cards/{$card->id}", [
                'prompt' => ['cueText' => '会社'],
                'answer' => ['meaning' => 'company'],
                'variantGroupId' => '   ',
                'variantSentenceId' => "\t",
                'variantKind' => null,
                'variantStage' => null,
                'variantStatus' => null,
                'variantUnlockedAt' => null,
            ])
            ->assertOk()
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card->refresh();
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame(CardSyncPayload::fromCard($card), $entry->payload);
    }

    public function test_it_returns_unchanged_when_variant_metadata_matches_existing_values(): void
    {
        $user = $this->signIn();
        $prompt = ['cueText' => '会社'];
        $answer = ['meaning' => 'company'];
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => $prompt,
            'answer_json' => $answer,
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);
        $card->refresh();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/study/cards/{$card->id}", [
                'prompt' => $prompt,
                'answer' => $answer,
                'variantGroupId' => ' keep-group ',
                'variantSentenceId' => ' keep-sentence ',
                'variantKind' => ' SENTENCE_AUDIO_RECOGNITION ',
                'variantStage' => ' +2 ',
                'variantStatus' => ' LOCKED ',
                'variantUnlockedAt' => '2026-06-05T14:15:00.987654Z',
            ])
            ->assertOk()
            ->assertJsonPath('variantGroupId', 'keep-group')
            ->assertJsonPath('variantSentenceId', 'keep-sentence')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Locked->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-05T14:15:00.000Z');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $this->assertSame('2026-06-05T14:15:00.000000Z', $card->refresh()->variant_unlocked_at?->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }
}
