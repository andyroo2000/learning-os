<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;
use App\Support\DateTime\ServerTimestamp;
use DateTimeInterface;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final class StudyBrowserCardAggregate
{
    private const SOURCE_KIND_NATIVE = 'native';

    private function __construct() {}

    public static function noteIdFor(Card $card): string
    {
        return $card->source_note_id === null ? (string) $card->id : (string) $card->source_note_id;
    }

    public static function sourceKindFor(Card $card): string
    {
        // Note groups are imported atomically; the deterministic first card represents group provenance.
        // Legacy blank provenance still falls back to native, even when sibling cards carry imported metadata.
        return is_string($card->source_kind) && $card->source_kind !== ''
            ? $card->source_kind
            : self::SOURCE_KIND_NATIVE;
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    public static function reviewCount(Collection $cards): int
    {
        return $cards->sum(fn (Card $card): int => (int) ($card->getAttribute('review_events_count') ?? 0));
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    public static function lastReviewedAt(Collection $cards): ?string
    {
        return $cards
            ->map(fn (Card $card): ?string => self::reviewAggregateTimestamp($card->getAttribute('review_events_max_reviewed_at')))
            ->filter()
            // ServerTimestamp emits fixed-width UTC ISO strings, so lexicographic max is chronological max.
            ->max();
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    public static function earliestTimestamp(Collection $cards, string $attribute): string
    {
        return self::boundaryTimestamp($cards, $attribute, latest: false);
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    public static function latestTimestamp(Collection $cards, string $attribute): string
    {
        return self::boundaryTimestamp($cards, $attribute, latest: true);
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return list<array{cardId: string, reviewCount: int, lastReviewedAt: string|null}>
     */
    public static function cardStats(Collection $cards): array
    {
        return $cards
            ->map(fn (Card $card): array => [
                'cardId' => (string) $card->id,
                'reviewCount' => (int) ($card->getAttribute('review_events_count') ?? 0),
                'lastReviewedAt' => self::reviewAggregateTimestamp($card->getAttribute('review_events_max_reviewed_at')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    private static function boundaryTimestamp(Collection $cards, string $attribute, bool $latest): string
    {
        $timestamps = $cards
            ->map(fn (Card $card): string => self::cardTimestamp($card, $attribute))
            ->filter();

        $timestamp = $latest ? $timestamps->max() : $timestamps->min();

        return is_string($timestamp)
            ? $timestamp
            : throw new UnexpectedValueException("Study browser {$attribute} timestamp is missing or invalid.");
    }

    private static function cardTimestamp(Card $card, string $attribute): string
    {
        $timestamp = ServerTimestamp::toJson($card->getAttribute($attribute));

        return $timestamp
            ?? throw new UnexpectedValueException("Study browser {$attribute} timestamp is missing or invalid.");
    }

    public static function reviewAggregateTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Raw aggregate values arrive as strings today; the DateTimeInterface arm keeps direct callers defensive.
        if ($value instanceof DateTimeInterface || is_string($value)) {
            return ServerTimestamp::toJson($value)
                ?? throw new UnexpectedValueException('Study browser review aggregate is not a valid timestamp.');
        }

        throw new UnexpectedValueException('Study browser review aggregate has an unexpected timestamp type.');
    }
}
