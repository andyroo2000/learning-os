<?php

namespace App\Domain\Reviews\Models;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use Database\Factories\CardReviewEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['card_id', 'rating', 'reviewed_at', 'duration_ms', 'client_event_id', 'device_id', 'client_created_at'])]
class CardReviewEvent extends Model
{
    public const STORAGE_DATE_FORMAT = 'Y-m-d H:i:s.v';

    /** @use HasFactory<CardReviewEventFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings;

    protected static function newFactory(): CardReviewEventFactory
    {
        return CardReviewEventFactory::new();
    }

    /**
     * Soft-deleted cards and decks are excluded by their relationship global scopes.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedByActiveCardDeck(Builder $query, int $userId): Builder
    {
        return $query->whereHas('card.deck', fn (Builder $query) => $query->where('user_id', $userId));
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function cardDeckId(): ?string
    {
        if ($this->relationLoaded('card')) {
            return $this->card?->deck_id;
        }

        if (array_key_exists('card_deck_id', $this->getAttributes())) {
            $deckId = $this->getAttribute('card_deck_id');

            return $deckId === null ? null : (string) $deckId;
        }

        return $this->cardForContext()?->deck_id;
    }

    public function cardCourseId(): ?string
    {
        if ($this->relationLoaded('card')) {
            return $this->card?->deckCourseId();
        }

        if (array_key_exists('card_course_id', $this->getAttributes())) {
            $courseId = $this->getAttribute('card_course_id');

            return $courseId === null ? null : (string) $courseId;
        }

        return $this->cardForContext()?->deckCourseId();
    }

    public function setClientTimestamps(Carbon $reviewedAt, ?Carbon $clientCreatedAt): void
    {
        // These values are already normalized by ReviewCardData. Assign their
        // storage strings directly so Eloquent's second-precision default does
        // not discard milliseconds on client review writes.
        $this->attributes['reviewed_at'] = self::formatClientTimestampForStorage($reviewedAt);
        $this->attributes['client_created_at'] = $clientCreatedAt === null
            ? null
            : self::formatClientTimestampForStorage($clientCreatedAt);
    }

    public static function formatClientTimestampForStorage(Carbon $timestamp): string
    {
        // Callers must pass the UTC millisecond value normalized by ReviewCardData.
        return $timestamp->microsecond === 0
            ? $timestamp->format('Y-m-d H:i:s')
            : $timestamp->format(self::STORAGE_DATE_FORMAT);
    }

    private function cardForContext(): ?Card
    {
        $card = $this->card()
            ->withTrashed()
            ->with(['deck' => fn ($query) => $query->withTrashed()])
            ->first();

        $this->setRelation('card', $card);

        return $card;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => CardReviewRating::class,
            'reviewed_at' => 'datetime',
            'duration_ms' => 'integer',
            'source_review_id' => 'integer',
            'source_card_id' => 'integer',
            'source_ease' => 'integer',
            'source_interval' => 'integer',
            'source_last_interval' => 'integer',
            'source_factor' => 'integer',
            'source_time_ms' => 'integer',
            'source_review_type' => 'integer',
            'raw_payload_json' => 'array',
            'client_created_at' => 'datetime',
            'card_state_before' => 'array',
            'scheduler_state_before' => 'array',
            'scheduler_state_after' => 'array',
        ];
    }
}
