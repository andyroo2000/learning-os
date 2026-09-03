<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Collection;
use LogicException;
use RuntimeException;

class ReviewCardBatchConflictValidator
{
    /**
     * @param  Collection<int, array{id: string, card_id: string, sync_key: string, client_supplied_id: bool, identity: CardReviewEventIdentity}>  $preparedItems
     * @param  Collection<string, CardReviewEvent>  $reviewEventsBySyncKey
     * @param  Collection<string, CardReviewEvent>  $reviewEventsById
     * @param  Collection<string, Card>  $cardsById
     */
    public function assertMatches(
        Collection $preparedItems,
        Collection $reviewEventsBySyncKey,
        Collection $reviewEventsById,
        Collection $cardsById,
    ): void {
        $this->assertSyncKeysMatch($preparedItems, $reviewEventsBySyncKey, $cardsById);
        $this->assertProvidedIdsMatch($preparedItems, $reviewEventsById, $cardsById);
    }

    private function assertSyncKeysMatch(
        Collection $preparedItems,
        Collection $reviewEventsBySyncKey,
        Collection $cardsById,
    ): void {
        foreach ($preparedItems->groupBy('sync_key') as $syncKey => $items) {
            $item = $items->firstWhere('client_supplied_id', true) ?? $items->first();
            $card = $this->cardFor($item, $cardsById);
            $identity = $item['identity'];

            $this->assertRequestsMatch($items, $identity, $card);
            $this->assertStoredEventMatches($reviewEventsBySyncKey->get($syncKey), $identity, $card);
        }
    }

    private function assertProvidedIdsMatch(
        Collection $preparedItems,
        Collection $reviewEventsById,
        Collection $cardsById,
    ): void {
        $groups = $preparedItems
            ->filter(fn (array $item): bool => $item['client_supplied_id'])
            ->groupBy('id');

        foreach ($groups as $items) {
            $item = $items->first();
            $identity = $item['identity'];
            $card = $this->cardFor($item, $cardsById);

            $this->assertRequestsMatch($items, $identity, $card);
            $this->assertStoredEventMatches($reviewEventsById->get($item['id']), $identity, $card);
        }
    }

    private function cardFor(array $item, Collection $cardsById): Card
    {
        return $cardsById->get($item['card_id'])
            ?? throw new RuntimeException('Card missing while validating review event conflicts.');
    }

    private function assertRequestsMatch(
        Collection $items,
        CardReviewEventIdentity $identity,
        Card $card,
    ): void {
        foreach ($items as $item) {
            if (! $identity->matchesRequest($item['identity'])) {
                throw CardReviewEventConflictException::conflict($card->ownerUserId());
            }
        }
    }

    private function assertStoredEventMatches(
        ?CardReviewEvent $reviewEvent,
        CardReviewEventIdentity $identity,
        Card $card,
    ): void {
        if ($reviewEvent === null) {
            return;
        }

        $this->assertReviewEventBelongsToCardOwner($reviewEvent, $card);

        if (! $identity->matchesReviewEvent($reviewEvent)) {
            throw CardReviewEventConflictException::conflict($this->ownerIdFor($reviewEvent));
        }
    }

    private function assertReviewEventBelongsToCardOwner(CardReviewEvent $reviewEvent, Card $card): void
    {
        $conflictingUserId = $this->ownerIdFor($reviewEvent);

        if ($conflictingUserId !== $card->ownerUserId()) {
            throw CardReviewEventConflictException::conflict($conflictingUserId);
        }
    }

    private function ownerIdFor(CardReviewEvent $reviewEvent): int
    {
        if (! $reviewEvent->relationLoaded('card')) {
            throw new LogicException('Review event card relation must be eager-loaded for conflict resolution.');
        }

        $card = $reviewEvent->card;

        if ($card === null) {
            // Soft-deleted cards are loaded with withTrashed(); null here means broken historical data.
            throw new LogicException('Review event card owner could not be resolved.');
        }

        return $card->ownerUserId();
    }
}
