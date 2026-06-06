<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateStudyCardDraftActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_generating_study_card_draft(): void
    {
        $user = User::factory()->create();

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::ProductionImage,
            cardType: CardType::Production,
            promptJson: ['cueText' => 'company'],
            answerJson: ['expression' => '会社', 'meaning' => 'company'],
            imagePlacement: StudyCardImagePlacement::Both,
            imagePrompt: '  A sunny office  ',
        ));

        $draft->refresh();

        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertSame(StudyCardCreationKind::ProductionImage, $draft->creation_kind);
        $this->assertSame(CardType::Production, $draft->card_type);
        $this->assertSame(['cueText' => 'company'], $draft->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $draft->answer_json);
        $this->assertSame(StudyCardImagePlacement::Both, $draft->image_placement);
        $this->assertSame('A sunny office', $draft->image_prompt);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);
        $this->assertNull($draft->error_message);
    }

    public function test_it_defaults_image_fields_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: ' cloze ',
            cardType: ' CLOZE ',
            promptJson: ['clozeText' => '試合に[勝ちました]。'],
            answerJson: ['meaning' => 'won'],
            imagePrompt: '   ',
        ));

        $this->assertSame(StudyCardImagePlacement::None, $draft->refresh()->image_placement);
        $this->assertNull($draft->image_prompt);
    }

    public function test_it_rejects_card_type_mismatches_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('cardType must match creationKind.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::Cloze,
            cardType: CardType::Recognition,
            promptJson: ['clozeText' => '試合に[勝ちました]。'],
            answerJson: ['meaning' => 'won'],
        ));
    }

    public function test_it_rejects_oversized_image_prompts_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('imagePrompt must be 1000 characters or fewer.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            imagePrompt: str_repeat('a', CreateStudyCardDraftData::MAX_IMAGE_PROMPT_LENGTH + 1),
        ));
    }

    public function test_it_rejects_creates_when_the_user_draft_queue_is_full(): void
    {
        $user = User::factory()->create();
        StudyCardDraft::factory()
            ->for($user)
            ->count(CreateStudyCardDraftAction::MAX_DRAFTS_PER_USER)
            ->create();

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Draft queue is full. Delete some drafts before adding more.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['answerText' => 'dog'],
        ));
    }
}
