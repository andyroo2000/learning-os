<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use Illuminate\Support\Facades\DB;

class CreateStudyCardDraftAction
{
    public const MAX_DRAFTS_PER_USER = 2000;

    /**
     * @param  null|callable(string): void  $afterCommit  Called after commit; omit only when the caller will advance the draft lifecycle itself.
     */
    public function handle(CreateStudyCardDraftData $data, ?callable $afterCommit = null): StudyCardDraft
    {
        if ($data->cardType !== $data->creationKind->cardType()) {
            throw StudyCardDraftValidationException::cardTypeMustMatchCreationKind();
        }

        return DB::transaction(function () use ($afterCommit, $data): StudyCardDraft {
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
            $draft->card_type = $data->cardType;
            $draft->prompt_json = $data->promptJson;
            $draft->answer_json = $data->answerJson;
            $draft->image_placement = $data->imagePlacement;
            $draft->image_prompt = $data->imagePrompt;
            $draft->preview_audio_json = null;
            $draft->preview_audio_role = null;
            $draft->preview_image_json = null;
            $draft->error_message = null;
            $draft->save();

            if ($afterCommit !== null) {
                DB::afterCommit(static fn () => $afterCommit($draft->id));
            }

            return $draft;
        });
    }
}
