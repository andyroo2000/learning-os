<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ReorderNewCardQueueAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    /**
     * @param  list<string>  $cardIds
     * @return Collection<int, Card>
     */
    public function handle(int $userId, array $cardIds): Collection
    {
        $cardIds = $this->normalizeCardIds($cardIds);

        if (count($cardIds) < 1 || count($cardIds) > NewCardQueueLimits::PAGE_SIZE_MAX) {
            throw CardValidationException::invalidCardIds(
                'card_ids must include between 1 and '.NewCardQueueLimits::PAGE_SIZE_MAX.' cards.',
            );
        }

        if (count(array_unique($cardIds)) !== count($cardIds)) {
            throw CardValidationException::invalidCardIds('card_ids must not contain duplicates.');
        }

        return DB::transaction(function () use ($userId, $cardIds): Collection {
            $this->lockQueueOwner($userId);
            $databaseCardIds = collect($cardIds)
                ->flatMap(fn (string $cardId): array => CanonicalUlid::databaseCandidates($cardId))
                ->unique()
                ->values()
                ->all();

            $cardsQuery = Card::query()
                ->select('cards.*')
                ->with(['deck:id,user_id,course_id'])
                ->join('decks', 'decks.id', '=', 'cards.deck_id')
                ->where('decks.user_id', $userId)
                ->whereNull('decks.deleted_at')
                ->where('cards.study_status', CardStudyStatus::New->value)
                ->whereIn('cards.id', $databaseCardIds);

            $cards = NewCardQueueOrdering::nullPositionsLast($cardsQuery)
                ->orderBy('cards.id')
                ->lockForUpdate()
                ->get();

            if ($cards->count() !== count($cardIds)) {
                throw CardValidationException::invalidCardIds(
                    'Every reordered card must be an active new card owned by the user.',
                );
            }

            $availablePositions = $this->availablePositions($userId, $cards);
            $positionsByCardId = array_combine($cardIds, $availablePositions);

            $cardsById = $cards->keyBy(
                fn (Card $card): string => CanonicalUlid::normalize($card->id),
            );
            $orderedCards = collect();

            foreach ($cardIds as $cardId) {
                /** @var Card $card */
                $card = $cardsById->get($cardId);
                $card->new_queue_position = $positionsByCardId[$cardId];

                if ($card->isDirty('new_queue_position')) {
                    $card->saveOrFail();

                    $this->recordSyncFeedEntry->handle(
                        RecordSyncFeedEntryData::fromInput(
                            userId: $userId,
                            domain: CardSyncPayload::DOMAIN,
                            resourceType: CardSyncPayload::RESOURCE_TYPE,
                            resourceId: $card->id,
                            operation: SyncFeedOperation::Update->value,
                            payload: CardSyncPayload::fromCard($card),
                        ),
                    );
                }

                $orderedCards->push($card);
            }

            return $orderedCards;
        });
    }

    /**
     * @param  list<string>  $cardIds
     * @return list<string>
     */
    private function normalizeCardIds(array $cardIds): array
    {
        return array_map(function (mixed $cardId): string {
            if (! is_string($cardId)) {
                throw CardValidationException::invalidCardIds('Each card_id must be a valid ULID.');
            }

            $normalized = CanonicalUlid::normalize($cardId);

            if ($normalized === '' || ! Str::isUlid($normalized)) {
                throw CardValidationException::invalidCardIds('Each card_id must be a valid ULID.');
            }

            return $normalized;
        }, $cardIds);
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return list<int>
     */
    private function availablePositions(int $userId, Collection $cards): array
    {
        $nextSyntheticPosition = null;
        $positions = [];

        foreach ($cards as $card) {
            if ($card->new_queue_position !== null) {
                $positions[] = $card->new_queue_position;

                continue;
            }

            if ($nextSyntheticPosition === null) {
                $nextSyntheticPosition = $this->newCardQueuePosition()->nextForUser($userId);
            }

            $positions[] = $nextSyntheticPosition;
            $nextSyntheticPosition++;
        }

        sort($positions);

        return $positions;
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }

    private function lockQueueOwner(int $userId): void
    {
        $lockedUserId = DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->value('id');

        if ($lockedUserId === null) {
            throw new LogicException('New-card queue owner could not be locked.');
        }
    }
}
