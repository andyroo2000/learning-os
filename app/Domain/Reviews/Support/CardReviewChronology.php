<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Identifiers\CanonicalUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Defines the one durable ordering for review scheduling, snapshots, and undo.
 */
final class CardReviewChronology
{
    private function __construct() {}

    /**
     * @param  iterable<int, string>  $cardIds
     * @return Collection<string, CardReviewEvent>
     */
    public static function latestForCards(iterable $cardIds): Collection
    {
        $cardIds = collect($cardIds)->unique()->values();

        if ($cardIds->isEmpty()) {
            return collect();
        }

        return CardReviewEvent::query()
            ->whereIn('card_id', $cardIds)
            ->whereNotExists(function (QueryBuilder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('card_review_events as newer_review_events')
                    ->whereColumn('newer_review_events.card_id', 'card_review_events.card_id')
                    ->where(function (QueryBuilder $query): void {
                        $query
                            ->whereColumn('newer_review_events.reviewed_at', '>', 'card_review_events.reviewed_at')
                            ->orWhere(function (QueryBuilder $query): void {
                                $query
                                    ->whereColumn('newer_review_events.reviewed_at', 'card_review_events.reviewed_at')
                                    ->whereColumn('newer_review_events.id', '>', 'card_review_events.id');
                            });
                    });
            })
            ->get()
            ->keyBy(fn (CardReviewEvent $reviewEvent): string => $reviewEvent->card_id);
    }

    public static function assertCanAppend(
        Card $card,
        ?CarbonInterface $latestReviewedAt,
        ?string $latestReviewEventId,
        CarbonInterface $candidateReviewedAt,
        string $candidateReviewEventId,
    ): void {
        $cardReviewedAt = $card->last_reviewed_at;

        if ($cardReviewedAt !== null) {
            if ($candidateReviewedAt->lessThan($cardReviewedAt)) {
                throw CardReviewEventConflictException::outOfOrder($card->ownerUserId());
            }

            if (
                $candidateReviewedAt->equalTo($cardReviewedAt)
                && ($latestReviewedAt === null || ! $latestReviewedAt->equalTo($cardReviewedAt))
            ) {
                // The card records a review at this instant but no event ID can establish a safe tie order.
                throw CardReviewEventConflictException::outOfOrder($card->ownerUserId());
            }
        }

        if (
            $latestReviewedAt !== null
            && $latestReviewEventId !== null
            && self::compare(
                $candidateReviewedAt,
                $candidateReviewEventId,
                $latestReviewedAt,
                $latestReviewEventId,
            ) <= 0
        ) {
            throw CardReviewEventConflictException::outOfOrder($card->ownerUserId());
        }
    }

    public static function compare(
        CarbonInterface $leftReviewedAt,
        string $leftReviewEventId,
        CarbonInterface $rightReviewedAt,
        string $rightReviewEventId,
    ): int {
        if ($leftReviewedAt->lessThan($rightReviewedAt)) {
            return -1;
        }

        if ($leftReviewedAt->greaterThan($rightReviewedAt)) {
            return 1;
        }

        return strcmp(
            CanonicalUlid::normalize($leftReviewEventId),
            CanonicalUlid::normalize($rightReviewEventId),
        );
    }

    public static function hasNewerEvent(CardReviewEvent $reviewEvent): bool
    {
        return CardReviewEvent::query()
            ->where('card_id', $reviewEvent->card_id)
            ->where(function (Builder $query) use ($reviewEvent): void {
                $query
                    ->where('reviewed_at', '>', $reviewEvent->reviewed_at)
                    ->orWhere(function (Builder $query) use ($reviewEvent): void {
                        $query
                            ->where('reviewed_at', $reviewEvent->reviewed_at)
                            ->where('id', '>', $reviewEvent->id);
                    });
            })
            ->exists();
    }
}
