<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Values\CardReviewAppendCandidate;
use App\Domain\Reviews\Values\LatestCardReviewChronology;
use App\Support\Identifiers\CanonicalUlid;
use Carbon\CarbonInterface;
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
            ->select([
                'id',
                'card_id',
                'reviewed_at',
                'client_event_id',
                'device_id',
                'client_created_at',
            ])
            ->whereIn('card_id', $cardIds)
            // Let the (card_id, reviewed_at, id) index find the newest timestamp.
            // Equal-time legacy rows are rare and are reduced by normalized ID in PHP below,
            // avoiding LOWER(id) on the indexed hot path.
            ->whereNotExists(function (QueryBuilder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('card_review_events as newer_review_events')
                    ->whereColumn('newer_review_events.card_id', 'card_review_events.card_id')
                    ->whereColumn('newer_review_events.reviewed_at', '>', 'card_review_events.reviewed_at');
            })
            ->get()
            ->groupBy(fn (CardReviewEvent $reviewEvent): string => $reviewEvent->card_id)
            ->map(fn (Collection $reviewEvents): CardReviewEvent => $reviewEvents->reduce(
                function (?CardReviewEvent $latest, CardReviewEvent $candidate): CardReviewEvent {
                    if ($latest === null) {
                        return $candidate;
                    }

                    return self::compare(
                        $candidate->reviewed_at,
                        $candidate->id,
                        $latest->reviewed_at,
                        $latest->id,
                    ) > 0
                        ? $candidate
                        : $latest;
                },
            ));
    }

    public static function assertCanAppend(
        Card $card,
        ?CarbonInterface $latestReviewedAt,
        ?string $latestReviewEventId,
        CarbonInterface $candidateReviewedAt,
        string $candidateReviewEventId,
        bool $candidateHasExplicitId,
        bool $candidateHasSyncIdentity,
        bool $latestHasSyncIdentity,
    ): void {
        $latest = new LatestCardReviewChronology(
            $latestReviewedAt,
            $latestReviewEventId,
            $latestHasSyncIdentity,
        );
        $candidate = new CardReviewAppendCandidate(
            $candidateReviewedAt,
            $candidateReviewEventId,
            $candidateHasExplicitId,
            $candidateHasSyncIdentity,
        );

        self::assertAfterCardReview($card, $latest, $candidate);
        self::assertHasRequiredIdentity($card, $latest, $candidate);
        self::assertAfterLatestEvent($card, $latest, $candidate);
    }

    private static function assertAfterCardReview(
        Card $card,
        LatestCardReviewChronology $latest,
        CardReviewAppendCandidate $candidate,
    ): void {
        $cardReviewedAt = $card->last_reviewed_at;

        if ($cardReviewedAt === null) {
            return;
        }

        if ($candidate->reviewedAt->lessThan($cardReviewedAt)) {
            throw CardReviewEventConflictException::outOfOrder($card->ownerUserId());
        }

        if (self::matchesCardWithoutLatestEvent($cardReviewedAt, $latest, $candidate)) {
            // The card records a review at this instant but no event ID can establish a safe tie order.
            throw CardReviewEventConflictException::outOfOrder($card->ownerUserId());
        }
    }

    private static function matchesCardWithoutLatestEvent(
        CarbonInterface $cardReviewedAt,
        LatestCardReviewChronology $latest,
        CardReviewAppendCandidate $candidate,
    ): bool {
        return $candidate->reviewedAt->equalTo($cardReviewedAt)
            && ($latest->reviewedAt === null || ! $latest->reviewedAt->equalTo($cardReviewedAt));
    }

    private static function assertHasRequiredIdentity(
        Card $card,
        LatestCardReviewChronology $latest,
        CardReviewAppendCandidate $candidate,
    ): void {
        if (self::requiresIdentity($latest, $candidate)) {
            throw CardReviewEventConflictException::identityRequired($card->ownerUserId());
        }
    }

    private static function requiresIdentity(
        LatestCardReviewChronology $latest,
        CardReviewAppendCandidate $candidate,
    ): bool {
        return ! $candidate->hasExplicitId
            && $latest->reviewedAt !== null
            && $candidate->reviewedAt->equalTo($latest->reviewedAt)
            && (! $candidate->hasSyncIdentity || ! $latest->hasSyncIdentity);
    }

    private static function assertAfterLatestEvent(
        Card $card,
        LatestCardReviewChronology $latest,
        CardReviewAppendCandidate $candidate,
    ): void {
        if ($latest->reviewedAt === null || $latest->reviewEventId === null) {
            return;
        }

        if (self::compare(
            $candidate->reviewedAt,
            $candidate->reviewEventId,
            $latest->reviewedAt,
            $latest->reviewEventId,
        ) <= 0) {
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

    public static function hasCompleteSyncIdentity(?CardReviewEvent $reviewEvent): bool
    {
        return $reviewEvent?->client_event_id !== null
            && $reviewEvent->device_id !== null
            && $reviewEvent->client_created_at !== null;
    }

    public static function hasNewerEvent(CardReviewEvent $reviewEvent): bool
    {
        $latest = self::latestForCards([$reviewEvent->card_id])->get($reviewEvent->card_id);

        return $latest !== null
            && self::compare(
                $reviewEvent->reviewed_at,
                $reviewEvent->id,
                $latest->reviewed_at,
                $latest->id,
            ) < 0;
    }
}
