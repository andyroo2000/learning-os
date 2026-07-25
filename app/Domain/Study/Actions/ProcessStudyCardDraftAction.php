<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\StudyCardDraftEnricher;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessStudyCardDraftAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
        private readonly StudyCardDraftEnricher $enricher,
    ) {}

    public function handle(string $draftId): ?StudyCardDraft
    {
        $canonicalDraftId = CanonicalUlid::normalize($draftId);

        if (! Str::isUlid($canonicalDraftId)) {
            return null;
        }

        $draft = DB::transaction(function () use ($canonicalDraftId): ?StudyCardDraft {
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

            return $draft;
        });

        if ($draft === null || ! self::canProcess($draft)) {
            return $draft;
        }

        try {
            $this->validateSeedPayloads($draft);
        } catch (StudyCardDraftValidationException $e) {
            return $this->failValidation($canonicalDraftId, $e);
        }

        // Provider work runs without a database lock. The final transaction merges only fields
        // that were missing from both this seed snapshot and the latest user-edited row.
        $seedPrompt = $draft->prompt_json;
        $seedAnswer = $draft->answer_json;
        $seedImagePrompt = $draft->image_prompt;
        $enriched = $this->enricher->enrich($draft);

        return DB::transaction(function () use (
            $canonicalDraftId,
            $enriched,
            $seedAnswer,
            $seedImagePrompt,
            $seedPrompt,
        ): ?StudyCardDraft {
            $lockedDraft = StudyCardDraft::query()
                ->whereKey($canonicalDraftId)
                ->lockForUpdate()
                ->first();
            if ($lockedDraft === null || ! self::canProcess($lockedDraft)) {
                return $lockedDraft;
            }

            $lockedDraft->prompt_json = $this->mergeGeneratedFields(
                $lockedDraft->prompt_json,
                $seedPrompt,
                $enriched['prompt'],
            );
            $lockedDraft->answer_json = $this->mergeGeneratedFields(
                $lockedDraft->answer_json,
                $seedAnswer,
                $enriched['answer'],
            );
            if (
                $this->isMissing($seedImagePrompt)
                && $this->isMissing($lockedDraft->image_prompt)
            ) {
                $lockedDraft->image_prompt = $enriched['imagePrompt'];
            }

            try {
                $this->validateSeedPayloads($lockedDraft);
            } catch (StudyCardDraftValidationException $e) {
                self::markAsFailed($lockedDraft, $e->getMessage());
                $this->recordStudyCardDraftSyncEntry->handle(
                    $lockedDraft,
                    SyncFeedOperation::Update,
                );

                return $lockedDraft;
            }

            $lockedDraft->status = StudyManualCardDraftStatus::Ready;
            $lockedDraft->error_message = null;
            $lockedDraft->save();
            $this->recordStudyCardDraftSyncEntry->handle(
                $lockedDraft,
                SyncFeedOperation::Update,
            );

            return $lockedDraft;
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

    private function failValidation(
        string $draftId,
        StudyCardDraftValidationException $exception,
    ): ?StudyCardDraft {
        return DB::transaction(function () use ($draftId, $exception): ?StudyCardDraft {
            $draft = StudyCardDraft::query()
                ->whereKey($draftId)
                ->lockForUpdate()
                ->first();
            if ($draft === null || ! self::canProcess($draft)) {
                return $draft;
            }

            self::markAsFailed($draft, $exception->getMessage());
            $this->recordStudyCardDraftSyncEntry->handle(
                $draft,
                SyncFeedOperation::Update,
            );

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $enriched
     * @return array<string, mixed>
     */
    private function mergeGeneratedFields(
        array $latest,
        array $seed,
        array $enriched,
    ): array {
        foreach ($enriched as $key => $value) {
            if (
                ! is_string($key)
                || ! $this->isMissing($seed[$key] ?? null)
                || ! $this->isMissing($latest[$key] ?? null)
            ) {
                continue;
            }

            $latest[$key] = $value;
        }

        return $latest;
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
