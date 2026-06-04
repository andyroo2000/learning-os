<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCardStudyStatusAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(Card $card, CardStudyStatus|string $studyStatus): UpdateCardResult
    {
        $studyStatus = $this->normalizeStudyStatus($studyStatus);

        return DB::transaction(function () use ($card, $studyStatus): UpdateCardResult {
            $card->study_status = $studyStatus;

            if ($studyStatus === CardStudyStatus::New) {
                $card->due_at = null;
                $card->introduced_at = null;
                $card->failed_at = null;
                $card->last_reviewed_at = null;
            }

            $wasUpdated = $card->isDirty([
                'study_status',
                'due_at',
                'introduced_at',
                'failed_at',
                'last_reviewed_at',
            ]);

            $card->saveOrFail();

            if (! $wasUpdated) {
                return UpdateCardResult::unchanged($card);
            }

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $card->ownerUserId(),
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($card),
                ),
            );

            return UpdateCardResult::updated($card);
        });
    }

    private function normalizeStudyStatus(CardStudyStatus|string $studyStatus): CardStudyStatus
    {
        if ($studyStatus instanceof CardStudyStatus) {
            return $studyStatus;
        }

        $normalized = strtolower(trim($studyStatus));

        if ($normalized === '') {
            throw new InvalidArgumentException('Card study_status must not be blank.');
        }

        return CardStudyStatus::tryFrom($normalized)
            ?? throw new InvalidArgumentException(
                'Card study_status must be one of: '.implode(', ', CardStudyStatus::values()).'.',
            );
    }
}
