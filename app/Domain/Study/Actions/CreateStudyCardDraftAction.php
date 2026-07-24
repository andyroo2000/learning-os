<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\IntegrityConstraintViolation;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class CreateStudyCardDraftAction
{
    public function __construct(
        private readonly PrepareStudyCardDraftQueueSlotAction $prepareStudyCardDraftQueueSlot,
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
        /** @internal Test-only race seam; see CreateStudyCardDraftActionTest. */
        private readonly ?Closure $afterClientIdPrecheckMiss = null,
    ) {
        if ($afterClientIdPrecheckMiss !== null && ! app()->runningUnitTests()) {
            throw new LogicException('Study card draft creation race hooks may only be used in tests.');
        }
    }

    /**
     * @param  null|callable(string): void  $afterCommit  Called after commit; omit only when the caller will advance the draft lifecycle itself.
     */
    public function handle(CreateStudyCardDraftData $data, ?callable $afterCommit = null): StudyCardDraft
    {
        if ($data->cardType !== $data->creationKind->cardType()) {
            throw StudyCardDraftValidationException::cardTypeMustMatchCreationKind();
        }

        if ($data->id !== null) {
            if (! Str::isUlid($data->id)) {
                throw StudyCardDraftValidationException::invalidId();
            }

            $existingDraft = StudyCardDraft::query()->find($data->id);

            if ($existingDraft !== null) {
                return $this->matchingExistingDraft($existingDraft, $data);
            }

            if ($this->afterClientIdPrecheckMiss !== null) {
                ($this->afterClientIdPrecheckMiss)($data);
            }

            return $this->createWithClientId($data, $afterCommit);
        }

        return DB::transaction(function () use ($afterCommit, $data): StudyCardDraft {
            $this->prepareStudyCardDraftQueueSlot->handle($data->userId);

            $draft = $this->newDraft($data);
            $draft->save();

            $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Create);

            if ($afterCommit !== null) {
                DB::afterCommit(static fn () => $afterCommit($draft->id));
            }

            return $draft;
        });
    }

    private function createWithClientId(
        CreateStudyCardDraftData $data,
        ?callable $afterCommit,
    ): StudyCardDraft {
        $draft = $this->newDraft($data);
        $draft->id = $data->id;

        // Manual transaction control lets a primary-key race be resolved after rollback,
        // which is required on PostgreSQL before issuing the recovery query.
        DB::beginTransaction();

        try {
            $this->prepareStudyCardDraftQueueSlot->handle($data->userId);
            $draft->save();
        } catch (QueryException $exception) {
            DB::rollBack();

            if (! IntegrityConstraintViolation::matchesPrimaryKey($exception, $draft->getTable())) {
                throw $exception;
            }

            $existingDraft = StudyCardDraft::query()->find($data->id);

            if ($existingDraft === null) {
                throw $exception;
            }

            return $this->matchingExistingDraft($existingDraft, $data);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        try {
            $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Create);
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($afterCommit !== null) {
            $afterCommit($draft->id);
        }

        return $draft;
    }

    private function newDraft(CreateStudyCardDraftData $data): StudyCardDraft
    {
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

        return $draft;
    }

    private function matchingExistingDraft(
        StudyCardDraft $draft,
        CreateStudyCardDraftData $data,
    ): StudyCardDraft {
        if (
            $draft->user_id !== $data->userId
            || $draft->creation_kind !== $data->creationKind
            || $draft->card_type !== $data->cardType
            || $this->canonicalJsonValue($draft->prompt_json) !== $this->canonicalJsonValue($data->promptJson)
            || $this->canonicalJsonValue($draft->answer_json) !== $this->canonicalJsonValue($data->answerJson)
            || $draft->image_placement !== $data->imagePlacement
            || $draft->image_prompt !== $data->imagePrompt
            || $draft->variant_group_id !== $data->variantGroupId
            || $draft->variant_sentence_id !== $data->variantSentenceId
            || $draft->variant_kind !== $data->variantKind?->value
            || $draft->variant_stage !== $data->variantStage
            || $draft->variant_status !== $data->variantStatus?->value
            || $draft->variant_unlocked_at?->toJSON() !== $this->timestampJson($data->variantUnlockedAt)
        ) {
            throw StudyCardDraftConflictException::idMismatch($draft);
        }

        return $draft;
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn ($item) => is_array($item) ? $this->canonicalJsonValue($item) : $item,
            $value,
        );
    }

    private function timestampJson(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->toJSON();
    }
}
