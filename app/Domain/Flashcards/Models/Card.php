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

#[Fillable(['deck_id', 'front_text', 'back_text'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, HasUlids;

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
        $mediaAsset = new MediaAsset;

        return $this->belongsToMany(MediaAsset::class, 'card_media')
            ->orderBy($mediaAsset->getQualifiedKeyName())
            ->withTimestamps();
    }
}
