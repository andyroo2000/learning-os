<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardRevisionApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_returns_the_card_summary_when_the_update_is_unchanged(): void
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
        $this->assertNotNull($card->updated_at);
        $originalUpdatedAt = $card->updated_at->toJSON();
        $originalUpdatedAtResponse = ConvoLabTimestamp::serialize($card->updated_at);

        $response = $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => $prompt,
            'answer' => $answer,
            'expectedRevision' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('id', $card->id)
            ->assertJsonPath('revision', 0)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('variantGroupId', 'keep-group')
            ->assertJsonPath('variantSentenceId', 'keep-sentence')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Locked->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-05T14:15:00.000Z')
            ->assertJsonPath('updatedAt', $originalUpdatedAtResponse);

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card->refresh();

        $this->assertNotNull($card->updated_at);
        $this->assertSame($originalUpdatedAt, $card->updated_at->toJSON());
        $this->assertSame('keep-group', $card->variant_group_id);
        $this->assertSame('keep-sentence', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $card->variant_kind);
        $this->assertSame(2, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $card->variant_status);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $card->variant_unlocked_at?->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_expected_revision_rejects_a_stale_card_update_and_returns_the_current_card(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $this->assertSame(0, $card->content_revision);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '学校'],
            'answer' => ['meaning' => 'school'],
            'expectedRevision' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('prompt.cueText', '学校');

        $response = $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'expectedRevision' => 0,
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'card_revision_conflict')
            ->assertJsonPath('message', 'Study card content changed since it was loaded.')
            ->assertJsonPath('card.revision', 1)
            ->assertJsonPath('card.prompt.cueText', '学校')
            ->assertJsonPath('card.answer.meaning', 'school');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json('card'));

        $card->refresh();
        $this->assertSame(1, $card->content_revision);
        $this->assertSame(['cueText' => '学校'], $card->prompt_json);
        $this->assertSame(['meaning' => 'school'], $card->answer_json);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_expected_revision_is_optional_for_legacy_card_updates(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '学校'],
            'answer' => ['meaning' => 'school'],
        ])
            ->assertOk()
            ->assertJsonPath('revision', 1);

        $this->assertSame(1, $card->refresh()->content_revision);
    }
}
