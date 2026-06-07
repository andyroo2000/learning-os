<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class UpdateStudyCardDraftAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    public function handle(StudyCardDraft $draft, UpdateStudyCardDraftData $data): StudyCardDraft
    {
        if (! $data->hasAnyField()) {
            return StudyCardDraft::query()
                ->whereKey($draft->id)
                ->where('user_id', $draft->user_id)
                ->first() ?? throw StudyCardDraftNotFoundException::notFound();
        }

        return DB::transaction(function () use ($draft, $data): StudyCardDraft {
            $lockedDraft = StudyCardDraft::query()
                ->whereKey($draft->id)
                ->where('user_id', $draft->user_id)
                ->lockForUpdate()
                ->first();

            if ($lockedDraft === null) {
                throw StudyCardDraftNotFoundException::notFound();
            }

            if ($lockedDraft->status === StudyManualCardDraftStatus::Generating) {
                throw StudyCardDraftConflictException::generatingCannotBeEdited();
            }

            $effectivePreviewAudio = $data->hasPreviewAudio
                ? $data->previewAudioJson
                : $lockedDraft->preview_audio_json;

            if ($data->hasPreviewAudioRole && $data->previewAudioRole !== null && $effectivePreviewAudio === null) {
                throw StudyCardDraftValidationException::previewAudioRoleRequiresAudio();
            }

            // Status and error_message stay server-owned; generation/retry paths decide when to clear them.
            if ($data->hasPrompt) {
                $lockedDraft->prompt_json = $data->promptJson;
            }

            if ($data->hasAnswer) {
                $lockedDraft->answer_json = $data->answerJson;
            }

            if ($data->hasImagePlacement) {
                $lockedDraft->image_placement = $data->imagePlacement;
            }

            if ($data->hasImagePrompt) {
                $lockedDraft->image_prompt = $data->imagePrompt;
            }

            if ($data->hasPreviewAudio) {
                $lockedDraft->preview_audio_json = $data->previewAudioJson;

                if ($data->previewAudioJson === null && ! $data->hasPreviewAudioRole) {
                    $lockedDraft->preview_audio_role = null;
                }
            }

            if ($data->hasPreviewAudioRole) {
                $lockedDraft->preview_audio_role = $data->previewAudioRole;
            }

            if ($data->hasPreviewImage) {
                $lockedDraft->preview_image_json = $data->previewImageJson;
            }

            // Eloquent clears dirty state on save(), so capture this before persisting.
            $shouldRecordSync = $lockedDraft->isDirty();
            $lockedDraft->save();

            if ($shouldRecordSync) {
                $this->recordStudyCardDraftSyncEntry->handle($lockedDraft, SyncFeedOperation::Update);
            }

            return $lockedDraft;
        });
    }
}
