<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowStudyCardDraftCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_authentication(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertUnauthorized();
    }

    public function test_it_returns_an_owned_study_card_draft(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
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
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('status', StudyManualCardDraftStatus::Ready->value)
            ->assertJsonPath('creationKind', StudyCardCreationKind::ProductionImage->value)
            ->assertJsonPath('cardType', CardType::Production->value)
            ->assertJsonPath('prompt.cueImage.id', 'image-1')
            ->assertJsonPath('answer.answerText', '犬')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Prompt->value)
            ->assertJsonPath('imagePrompt', 'A friendly dog')
            ->assertJsonPath('previewAudioRole', StudyCardAudioRole::Answer->value)
            ->assertJsonPath('previewImage.id', 'image-1')
            ->assertJsonStructure([
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
                'errorMessage',
                'createdAt',
                'updatedAt',
            ]);
    }

    public function test_it_returns_failed_draft_status_and_error_message(): void
    {
        $draft = StudyCardDraft::factory()->failed()->for($this->signIn())->create();

        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('status', StudyManualCardDraftStatus::Error->value)
            ->assertJsonPath('errorMessage', 'Could not fill the remaining fields.');
    }

    public function test_it_normalizes_uppercase_route_draft_ids(): void
    {
        $draft = StudyCardDraft::factory()->for($this->signIn())->create();

        $this->getJson('/api/study/card-drafts/'.strtoupper($draft->id))
            ->assertOk()
            ->assertJsonPath('id', $draft->id);
    }

    public function test_it_hides_missing_and_cross_user_drafts(): void
    {
        $this->signIn();
        $otherDraft = StudyCardDraft::factory()->for(User::factory()->create())->create();

        $this->getJson("/api/study/card-drafts/{$otherDraft->id}")
            ->assertNotFound();

        $this->getJson('/api/study/card-drafts/'.strtolower((string) str()->ulid()))
            ->assertNotFound();

        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $otherDraft->id,
        ]);
    }
}
