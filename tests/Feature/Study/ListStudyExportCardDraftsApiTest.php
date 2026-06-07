<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportCardDraftsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/card-drafts')->assertUnauthorized();
    }

    public function test_index_returns_card_drafts_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstDraft = StudyCardDraft::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondDraft = StudyCardDraft::factory()->ready()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh36',
            'creation_kind' => StudyCardCreationKind::ProductionImage,
            'prompt_json' => ['cueImage' => ['id' => 'image-1']],
            'answer_json' => ['answerText' => '犬'],
            'image_placement' => StudyCardImagePlacement::Prompt,
            'image_prompt' => 'A friendly dog',
            'preview_image_json' => [
                'id' => 'image-1',
                'filename' => 'inu.png',
                'url' => '/api/study/media/image-1',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => StudyVocabVariantKind::SentenceTextRecognition,
            'variant_stage' => 2,
            'variant_status' => StudyVocabVariantStatus::Available,
            'variant_unlocked_at' => now(),
            'committed_card_id' => strtolower((string) str()->ulid()),
        ]);
        $otherDraft = StudyCardDraft::factory()->for($otherUser)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh37',
        ]);

        $this->getJson('/api/study/export/card-drafts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstDraft->id)
            ->assertJsonPath('data.0.status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('data.0.creationKind', StudyCardCreationKind::TextRecognition->value)
            ->assertJsonPath('data.0.cardType', CardType::Recognition->value)
            ->assertJsonPath('data.0.prompt.cueText', '犬')
            ->assertJsonPath('data.0.answer.answerText', 'dog')
            ->assertJsonPath('data.0.imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('data.1.id', $secondDraft->id)
            ->assertJsonPath('data.1.status', StudyManualCardDraftStatus::Ready->value)
            ->assertJsonPath('data.1.creationKind', StudyCardCreationKind::ProductionImage->value)
            ->assertJsonPath('data.1.cardType', CardType::Production->value)
            ->assertJsonPath('data.1.imagePlacement', StudyCardImagePlacement::Prompt->value)
            ->assertJsonPath('data.1.imagePrompt', 'A friendly dog')
            ->assertJsonPath('data.1.previewAudioRole', StudyCardAudioRole::Answer->value)
            ->assertJsonPath('data.1.previewImage.id', 'image-1')
            ->assertJsonPath('data.1.variantGroupId', 'vocab-group-1')
            ->assertJsonPath('data.1.variantSentenceId', 'sentence-1')
            ->assertJsonPath('data.1.variantKind', StudyVocabVariantKind::SentenceTextRecognition->value)
            ->assertJsonPath('data.1.variantStage', 2)
            ->assertJsonPath('data.1.variantStatus', StudyVocabVariantStatus::Available->value)
            ->assertJsonPath('data.1.variantUnlockedAt', $secondDraft->variant_unlocked_at->toJSON())
            ->assertJsonPath('data.1.committedCardId', $secondDraft->committed_card_id)
            ->assertJsonMissing([
                'id' => $otherDraft->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'status',
                        'creationKind',
                        'cardType',
                        'prompt',
                        'answer',
                        'imagePlacement',
                        'imagePrompt',
                        'previewAudio',
                        'previewAudioRole',
                        'previewImage',
                        'variantGroupId',
                        'variantSentenceId',
                        'variantKind',
                        'variantStage',
                        'variantStatus',
                        'variantUnlockedAt',
                        'errorMessage',
                        'committedCardId',
                        'createdAt',
                        'updatedAt',
                    ],
                ],
            ]);
    }
}
