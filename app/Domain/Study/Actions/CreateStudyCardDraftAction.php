<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;

class CreateStudyCardDraftAction
{
    public const MAX_DRAFTS_PER_USER = 2000;

    public function handle(CreateStudyCardDraftData $data): StudyCardDraft
    {
        if ($data->cardType !== $data->creationKind->cardType()) {
            throw StudyCardDraftValidationException::cardTypeMustMatchCreationKind();
        }

        // StudyCardDraft is not soft-deletable today, so this counts every persisted draft.
        $existingDraftCount = StudyCardDraft::query()
            ->where('user_id', $data->userId)
            ->count();

        // This matches ConvoLab's UX queue cap; concurrent requests may briefly exceed it.
        if ($existingDraftCount >= self::MAX_DRAFTS_PER_USER) {
            throw StudyCardDraftConflictException::queueFull();
        }

        $draft = new StudyCardDraft;
        $draft->user_id = $data->userId;
        $draft->status = StudyManualCardDraftStatus::Generating;
        $draft->creation_kind = $data->creationKind;
        // StudyCardDraft derives the persisted card_type from creation_kind on save.
        $draft->prompt_json = $data->promptJson;
        $draft->answer_json = $data->answerJson;
        $draft->image_placement = $data->imagePlacement;
        $draft->image_prompt = $data->imagePrompt;
        $draft->preview_audio_json = null;
        $draft->preview_audio_role = null;
        $draft->preview_image_json = null;
        $draft->error_message = null;
        $draft->save();

        return $draft;
    }
}
