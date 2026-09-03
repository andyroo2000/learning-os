<?php

namespace App\Domain\Flashcards\Support;

use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Support\Identifiers\CanonicalUlid;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use LogicException;

final class CreateCardConflictResolver
{
    public function resolve(Card $card, CreateCardData $data): Card
    {
        // Resolve owner before any conflict response so cross-user tombstones can still be
        // hidden as 404. If this fails, the cards.deck_id FK invariant has already broken.
        $ownerId = $this->ownerIdFor($card);

        if ($card->trashed()) {
            // Tombstones must win before metadata checks. Deleted IDs remain reserved,
            // so owners get a deletion signal and other users still get a hidden 404.
            // Cross-user tombstones still carry the deleted flag; HTTP checks ownership first.
            throw CardConflictException::cardDeleted($ownerId);
        }

        if ($this->deckIsTrashed($card)) {
            throw CardConflictException::deckDeleted($ownerId);
        }

        $this->assertIdentityMatches($card, $data, $ownerId);
        $this->assertContentMatches($card, $data, $ownerId);
        $this->assertVariantMatches($card, $data, $ownerId);
        $this->assertSchedulingMatches($card, $data, $ownerId);

        return $card;
    }

    public function ownerIdFor(Card $card): int
    {
        $deck = $this->deckForConflictResolution($card);
        $ownerId = $deck?->user_id;

        if ($ownerId === null) {
            // The deck relation is null despite the cards.deck_id cascade-on-delete FK.
            // Treat that as a data-integrity invariant failure and let it surface as a 500.
            Log::warning('Card conflict owner could not be resolved.', [
                'card_id' => $card->id,
                'deck_id' => $card->deck_id,
            ]);

            throw new LogicException('Card deck owner could not be resolved.');
        }

        return (int) $ownerId;
    }

    private function assertIdentityMatches(Card $card, CreateCardData $data, int $ownerId): void
    {
        $this->assertSame($ownerId, $data->userId, $ownerId);
        $this->assertSame(CanonicalUlid::normalize((string) $card->deck_id), $data->deckId, $ownerId);
    }

    private function assertContentMatches(Card $card, CreateCardData $data, int $ownerId): void
    {
        // The action stores trimmed text; trim stored values too so legacy/direct rows
        // compare by the same canonical content.
        $this->assertSame(trim($card->front_text ?? ''), $data->frontText, $ownerId);
        $this->assertSame(trim($card->back_text ?? ''), $data->backText, $ownerId);
        $this->assertSame($card->card_type ?? CardType::Recognition, $data->cardType, $ownerId);
        $this->assertSame(
            $this->canonicalJsonValue($card->prompt_json),
            $this->canonicalJsonValue($data->promptJson),
            $ownerId,
        );
        $this->assertSame(
            $this->canonicalJsonValue($card->answer_json),
            $this->canonicalJsonValue($data->answerJson),
            $ownerId,
        );
    }

    private function assertVariantMatches(Card $card, CreateCardData $data, int $ownerId): void
    {
        $this->assertSame($card->variant_group_id, $data->variantGroupId, $ownerId);
        $this->assertSame($card->variant_sentence_id, $data->variantSentenceId, $ownerId);
        $this->assertSame($card->variant_kind, $data->variantKind?->value, $ownerId);
        $this->assertSame($card->variant_stage, $data->variantStage, $ownerId);
        $this->assertSame($card->variant_status, $data->variantStatus?->value, $ownerId);
        $this->assertSame(
            $card->variant_unlocked_at?->toJSON(),
            $this->timestampJson($data->variantUnlockedAt),
            $ownerId,
        );
    }

    private function assertSchedulingMatches(Card $card, CreateCardData $data, int $ownerId): void
    {
        $this->assertSame($card->introduction_cohort_id, $data->introductionCohortId, $ownerId);
        $this->assertSame(
            $card->selection_policy ?? CardSelectionPolicy::Standard,
            $data->selectionPolicy,
            $ownerId,
        );
        $this->assertSame(
            $card->priority_until?->toJSON(),
            $this->timestampJson($data->priorityUntil),
            $ownerId,
        );
    }

    private function assertSame(mixed $actual, mixed $expected, int $ownerId): void
    {
        if ($actual !== $expected) {
            throw CardConflictException::conflict($ownerId);
        }
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

    private function deckIsTrashed(Card $card): bool
    {
        return $this->deckForConflictResolution($card)?->trashed() === true;
    }

    private function deckForConflictResolution(Card $card): ?Deck
    {
        if (! $card->relationLoaded('deck')) {
            throw new LogicException('Card deck relation must be eager-loaded for conflict resolution.');
        }

        return $card->deck;
    }
}
