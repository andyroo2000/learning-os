<?php

namespace App\Domain\Flashcards\Models;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

#[Fillable(['deck_id', 'front_text', 'back_text'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected static function newFactory(): CardFactory
    {
        return CardFactory::new();
    }

    /**
     * @return BelongsTo<Deck, $this>
     */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    /**
     * Prefer an eager-loaded deck when callers already have one; otherwise query only the owner ID.
     */
    public function ownerUserId(): int
    {
        if ($this->relationLoaded('deck') && $this->deck !== null) {
            return (int) $this->deck->user_id;
        }

        if (array_key_exists('owner_user_id', $this->getAttributes())) {
            return (int) $this->getAttribute('owner_user_id');
        }

        $userId = $this->deck()->withTrashed()->value('user_id');

        if ($userId === null) {
            throw new LogicException('Card deck owner could not be resolved.');
        }

        return (int) $userId;
    }

    /**
     * @return HasMany<CardReviewEvent, $this>
     */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(CardReviewEvent::class);
    }

    /**
     * @return BelongsToMany<MediaAsset, $this>
     */
    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'card_media')
            ->orderBy('media_assets.id')
            ->withTimestamps();
    }
}
