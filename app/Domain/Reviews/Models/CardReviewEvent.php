<?php

namespace App\Domain\Reviews\Models;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Database\Factories\CardReviewEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['card_id', 'rating', 'reviewed_at', 'client_event_id', 'device_id', 'client_created_at'])]
class CardReviewEvent extends Model
{
    /** @use HasFactory<CardReviewEventFactory> */
    use HasFactory, HasUlids;

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => CardReviewRating::class,
            'reviewed_at' => 'datetime',
            'client_created_at' => 'datetime',
        ];
    }
}
