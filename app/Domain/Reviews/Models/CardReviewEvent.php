<?php

namespace App\Domain\Reviews\Models;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use Database\Factories\CardReviewEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['card_id', 'rating', 'reviewed_at', 'duration_ms', 'client_event_id', 'device_id', 'client_created_at'])]
class CardReviewEvent extends Model
{
    /** @use HasFactory<CardReviewEventFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings;

    protected static function newFactory(): CardReviewEventFactory
    {
        return CardReviewEventFactory::new();
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
            'client_created_at' => 'datetime',
            'scheduler_state_before' => 'array',
            'scheduler_state_after' => 'array',
        ];
    }
}
