<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftStateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_generating_draft_edits(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Generating drafts cannot be edited yet.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        ));
    }

    public function test_it_rechecks_the_locked_draft_status_before_saving(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();

        StudyCardDraft::query()
            ->whereKey($draft->id)
            ->update(['status' => StudyManualCardDraftStatus::Generating->value]);

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Generating drafts cannot be edited yet.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        ));
    }

    public function test_it_rejects_drafts_deleted_before_the_locked_write(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();
        $draft->delete();

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        ));
    }

    public function test_it_rejects_drafts_deleted_before_empty_autosave_readback(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();
        $draft->delete();

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput());
    }
}
