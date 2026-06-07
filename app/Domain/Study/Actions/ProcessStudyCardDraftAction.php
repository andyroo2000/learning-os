<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;

class ProcessStudyCardDraftAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    public function handle(string $draftId): ?StudyCardDraft
    {
        $canonicalDraftId = CanonicalUlid::normalize($draftId);

        return DB::transaction(function () use ($canonicalDraftId): ?StudyCardDraft {
            $draft = StudyCardDraft::query()
                ->whereKey($canonicalDraftId)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                return null;
            }

            if (! self::canProcess($draft)) {
                return $draft;
            }

            try {
                $this->validateSeedPayloads($draft);
            } catch (StudyCardDraftValidationException $exception) {
                self::markAsFailed($draft, $exception->getMessage());
                $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Update);

                return $draft;
            }

            // This first processor slice finalizes already-supplied manual seed content.
            // Preserve preview outputs here so future AI/media enrichment can attach them before
            // this terminal save without the validator erasing worker-owned fields on success.
            $draft->status = StudyManualCardDraftStatus::Ready;
            $draft->error_message = null;
            $draft->save();
            $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Update);

            return $draft;
        });
    }

    public static function canProcess(StudyCardDraft $draft): bool
    {
        if ($draft->status !== StudyManualCardDraftStatus::Generating) {
            return false;
        }

        // Defensive no-op for committed-while-generating rows from legacy data or future workers.
        return $draft->committed_card_id === null;
    }

    /**
     * Caller must hold the draft row lock when racing workers may also update the draft.
     */
    public static function markAsFailed(StudyCardDraft $draft, string $message): void
    {
        $draft->status = StudyManualCardDraftStatus::Error;
        $draft->preview_audio_json = null;
        $draft->preview_audio_role = null;
        $draft->preview_image_json = null;
        $draft->error_message = $message;
        $draft->save();
    }

    private function validateSeedPayloads(StudyCardDraft $draft): void
    {
        if (! is_array($draft->prompt_json) || ! is_array($draft->answer_json)) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        StudyCardPayloadShapeValidator::assertDraftPayloadsAreValid($draft->prompt_json, $draft->answer_json);
    }
}
