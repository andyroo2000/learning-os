<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class CreateStudyCardDraftAction
{
    public function __construct(
        private readonly PrepareStudyCardDraftQueueSlotAction $prepareStudyCardDraftQueueSlot,
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    /**
     * @param  null|callable(string): void  $afterCommit  Called after commit; omit only when the caller will advance the draft lifecycle itself.
     */
    public function handle(CreateStudyCardDraftData $data, ?callable $afterCommit = null): StudyCardDraft
    {
        if ($data->cardType !== $data->creationKind->cardType()) {
            throw StudyCardDraftValidationException::cardTypeMustMatchCreationKind();
        }

        return DB::transaction(function () use ($afterCommit, $data): StudyCardDraft {
            $this->prepareStudyCardDraftQueueSlot->handle($data->userId);

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
            $draft->variant_group_id = $data->variantGroupId;
            $draft->variant_sentence_id = $data->variantSentenceId;
            $draft->variant_kind = $data->variantKind?->value;
            $draft->variant_stage = $data->variantStage;
            $draft->variant_status = $data->variantStatus?->value;
            $draft->variant_unlocked_at = $data->variantUnlockedAt;
            $draft->error_message = null;
            $draft->save();

            // Create callers do not provide draft IDs, so reaching this point always means
            // a new sync resource was persisted rather than an existing draft retry.
            $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Create);

            if ($afterCommit !== null) {
                DB::afterCommit(static fn () => $afterCommit($draft->id));
            }

            return $draft;
        });
    }
}
