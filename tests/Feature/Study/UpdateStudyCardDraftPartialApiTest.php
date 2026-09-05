<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardDraftPartialApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_only_updates_present_fields(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $response = $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['expression' => '会社', 'meaning' => 'business'],
        ])
            ->assertOk()
            ->assertJsonPath('answer.meaning', 'business')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', 'Keep this')
            ->assertJsonPath('variantGroupId', 'keep-group')
            ->assertJsonPath('variantSentenceId', 'keep-sentence')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Locked->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-05T14:15:00.000000Z');

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft->refresh();
        $this->assertSame(['expression' => '会社', 'meaning' => 'business'], $draft->answer_json);
        $this->assertSame('Keep this', $draft->image_prompt);
        $this->assertSame('keep-group', $draft->variant_group_id);
        $this->assertSame('keep-sentence', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $draft->variant_kind);
        $this->assertSame(2, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $draft->variant_status);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $draft->variant_unlocked_at?->toJSON());
    }
}
