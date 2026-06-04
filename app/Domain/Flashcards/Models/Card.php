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

    public function deckCourseId(): ?string
    {
        if ($this->relationLoaded('deck')) {
            return $this->deck?->course_id;
        }

        if (array_key_exists('deck_course_id', $this->getAttributes())) {
            $courseId = $this->getAttribute('deck_course_id');

            return $courseId === null ? null : (string) $courseId;
        }

        $courseId = $this->deck()->withTrashed()->value('course_id');

        return $courseId === null ? null : (string) $courseId;
    }

    /**
     * Prefer an eager-loaded deck when callers already have one; otherwise query only the owner ID.
     */
    public function ownerUserId(): int
    {
        if ($this->relationLoaded('deck') && $this->deck !== null) {
            return $this->resolveOwnerUserId($this->deck->user_id);
        }

        if (array_key_exists('owner_user_id', $this->getAttributes())) {
            return $this->resolveOwnerUserId($this->getAttribute('owner_user_id'));
        }

        return $this->resolveOwnerUserId($this->deck()->withTrashed()->value('user_id'));
    }

    private function resolveOwnerUserId(int|string|null $userId): int
    {
        // Reject malformed numeric strings such as "3abc" before PHP casts them to a positive integer.
        if (is_string($userId) && ! ctype_digit($userId)) {
            throw new LogicException('Card deck owner could not be resolved.');
        }

        $resolvedUserId = (int) $userId;

        if ($resolvedUserId <= 0) {
            throw new LogicException('Card deck owner could not be resolved.');
        }

        return $resolvedUserId;
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
