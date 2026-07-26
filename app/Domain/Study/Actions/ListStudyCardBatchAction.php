<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ListStudyCardBatchAction
{
    public const MAX_ITEMS = 50;

    /**
     * @param  list<string>  $cardIds
     * @return Collection<int, Card>
     */
    public function handle(int $userId, array $cardIds): Collection
    {
        if (
            $cardIds === []
            || count($cardIds) > self::MAX_ITEMS
            || collect($cardIds)->contains(fn (mixed $id): bool => ! is_string($id))
        ) {
            throw new InvalidArgumentException(
                'Study card batches must contain between 1 and '.self::MAX_ITEMS.' IDs.',
            );
        }

        $normalizedIds = array_map(
            static fn (string $id): string => CanonicalUlid::normalize($id),
            $cardIds,
        );

        if (
            count(array_unique($normalizedIds)) !== count($normalizedIds)
            || collect($normalizedIds)->contains(fn (string $id): bool => ! Str::isUlid($id))
        ) {
            throw new InvalidArgumentException('Study card batch IDs must be distinct ULIDs.');
        }

        $databaseIds = collect($normalizedIds)
            ->flatMap(fn (string $id): array => CanonicalUlid::databaseCandidates($id))
            ->unique()
            ->values()
            ->all();
        $cards = Card::query()
            ->ownedByActiveDeck($userId)
            ->whereIn('cards.id', $databaseIds)
            ->get()
            ->keyBy(fn (Card $card): string => CanonicalUlid::normalize($card->id));

        return collect($normalizedIds)
            ->map(fn (string $id): ?Card => $cards->get($id))
            ->filter()
            ->values();
    }
}
