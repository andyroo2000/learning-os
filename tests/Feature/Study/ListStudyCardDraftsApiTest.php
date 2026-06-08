<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\ListStudyCardDraftsAction;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyCardDraftsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/card-drafts')->assertUnauthorized();
    }

    public function test_index_returns_empty_page_for_users_without_drafts(): void
    {
        $this->signIn();

        $this->getJson('/api/study/card-drafts')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('limit', ListStudyCardDraftsAction::DEFAULT_LIMIT)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonCount(0, 'drafts');
    }

    public function test_index_returns_drafts_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $olderDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $readyDraft = StudyCardDraft::factory()->ready()->for($user)->create([
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
            'variant_kind' => VocabVariantKind::SentenceCloze,
            'variant_stage' => 5,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDraft = StudyCardDraft::factory()->for(User::factory()->create())->create([
            'created_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/study/card-drafts')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('limit', ListStudyCardDraftsAction::DEFAULT_LIMIT)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonCount(2, 'drafts')
            ->assertJsonPath('drafts.0.id', $olderDraft->id)
            ->assertJsonPath('drafts.0.status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('drafts.0.creationKind', StudyCardCreationKind::TextRecognition->value)
            ->assertJsonPath('drafts.0.cardType', CardType::Recognition->value)
            ->assertJsonPath('drafts.0.prompt.cueText', '犬')
            ->assertJsonPath('drafts.0.answer.answerText', 'dog')
            ->assertJsonPath('drafts.0.imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('drafts.1.id', $readyDraft->id)
            ->assertJsonPath('drafts.1.status', StudyManualCardDraftStatus::Ready->value)
            ->assertJsonPath('drafts.1.creationKind', StudyCardCreationKind::ProductionImage->value)
            ->assertJsonPath('drafts.1.cardType', CardType::Production->value)
            ->assertJsonPath('drafts.1.imagePlacement', StudyCardImagePlacement::Prompt->value)
            ->assertJsonPath('drafts.1.imagePrompt', 'A friendly dog')
            ->assertJsonPath('drafts.1.previewAudioRole', StudyCardAudioRole::Answer->value)
            ->assertJsonPath('drafts.1.previewImage.id', 'image-1')
            ->assertJsonPath('drafts.1.variantGroupId', 'vocab-group-1')
            ->assertJsonPath('drafts.1.variantSentenceId', 'sentence-1')
            ->assertJsonPath('drafts.1.variantKind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('drafts.1.variantStage', 5)
            ->assertJsonPath('drafts.1.variantStatus', VocabVariantStatus::Locked->value)
            ->assertJsonPath('drafts.1.variantUnlockedAt', $readyDraft->variant_unlocked_at->toJSON())
            ->assertJsonPath('drafts.1.committedCardId', null)
            ->assertJsonMissing([
                'id' => $otherDraft->id,
            ])
            ->assertJsonStructure([
                'drafts' => [
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
                'total',
                'limit',
                'nextCursor',
            ]);
    }

    public function test_index_uses_limit_and_next_cursor(): void
    {
        $user = $this->signIn();
        $olderDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now()->subDay(),
        ]);
        $newerDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now(),
        ]);

        $firstPage = $this->getJson('/api/study/card-drafts?limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $olderDraft->id)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('limit', 1);

        $nextCursor = $firstPage->json('nextCursor');
        $this->assertNotNull($nextCursor);

        $this->getJson('/api/study/card-drafts?limit=1&cursor='.urlencode($nextCursor))
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $newerDraft->id)
            ->assertJsonPath('total', null)
            ->assertJsonPath('nextCursor', null);
    }

    public function test_index_uses_stable_id_tiebreaker_for_equal_created_at_values(): void
    {
        $user = $this->signIn();
        $sharedTimestamp = now()->subDay();

        $lowTieDraft = StudyCardDraft::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
            'created_at' => $sharedTimestamp,
        ]);
        $highTieDraft = StudyCardDraft::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh36',
            'created_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/study/card-drafts?limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonPath('drafts.0.id', $lowTieDraft->id);

        $nextCursor = $firstPage->json('nextCursor');
        $this->assertNotNull($nextCursor);

        $this->getJson('/api/study/card-drafts?limit=1&cursor='.urlencode($nextCursor))
            ->assertOk()
            ->assertJsonPath('drafts.0.id', $highTieDraft->id);
    }

    public function test_index_normalizes_cursor_and_limit_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $olderDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now()->subDay(),
        ]);
        $newerDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now(),
        ]);
        $cursor = app(ListStudyCardDraftsAction::class)->handle($user->id, limit: 1)['nextCursor'];

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/card-drafts?limit=%201%20&cursor='.urlencode(' '.$cursor.' '))
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $newerDraft->id)
            ->assertJsonMissing([
                'id' => $olderDraft->id,
            ]);
    }

    public function test_index_rejects_invalid_limit_and_cursor_values(): void
    {
        $this->signIn();

        $this->getJson('/api/study/card-drafts?limit=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);

        $this->getJson('/api/study/card-drafts?limit='.(ListStudyCardDraftsAction::MAX_LIMIT + 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);

        $this->getJson('/api/study/card-drafts?limit[]=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);

        $this->getJson('/api/study/card-drafts?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->getJson('/api/study/card-drafts?cursor[]=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);
    }
}
