<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PromoteNewCardToFrontAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    public function handle(int $userId, string $cardId): Card
    {
        $cardId = $this->normalizeCardId($cardId);

        return DB::transaction(function () use ($userId, $cardId): Card {
            // Every queue writer takes the owner lock before card rows. This makes the
            // ordered queue snapshot stable while retaining the card-row locks required
            // to serialize against review, status, and deletion mutations.
            $this->newCardQueuePosition()->lockOwner($userId);

            $lockedTarget = $this->queueQuery($userId)
                ->whereClientIdentifier($cardId)
                ->lockForUpdate()
                ->first();

            if ($lockedTarget === null) {
                throw (new ModelNotFoundException)->setModel(Card::class, [$cardId]);
            }

            $frontCard = NewCardQueueOrdering::nullPositionsLast($this->queueQuery($userId))
                ->orderBy('cards.created_at')
                ->orderBy('cards.id')
                ->lockForUpdate()
                ->first();

            if ($frontCard === null) {
                throw new LogicException('Locked new-card queue did not contain its target.');
            }

            if ($frontCard->is($lockedTarget) && $lockedTarget->new_queue_position !== null) {
                return $lockedTarget;
            }

            if ($frontCard->new_queue_position === PHP_INT_MIN) {
                throw new LogicException('New-card queue position range is exhausted.');
            }

            $lockedTarget->new_queue_position = $frontCard->new_queue_position === null
                ? 0
                : $frontCard->new_queue_position - 1;
            $lockedTarget->saveOrFail();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $lockedTarget->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($lockedTarget),
                ),
            );

            return $lockedTarget;
        });
    }

    private function normalizeCardId(string $cardId): string
    {
        $cardId = trim($cardId);

        if (Str::isUuid($cardId) || Str::isUlid($cardId)) {
            return $cardId;
        }

        // Malformed direct calls are hidden behind the same not-found boundary as
        // valid missing, deleted, and cross-user identifiers, without opening a lock.
        throw (new ModelNotFoundException)->setModel(Card::class);
    }

    /** @return Builder<Card> */
    private function queueQuery(int $userId): Builder
    {
        return Card::query()
            ->with(['deck:id,user_id,course_id'])
            ->ownedByActiveDeck($userId)
            ->whereProgressionAvailable()
            ->where('cards.study_status', CardStudyStatus::New->value);
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }
}
