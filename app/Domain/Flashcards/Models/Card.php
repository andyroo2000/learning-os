<?php

namespace App\Domain\Flashcards\Models;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

#[Fillable(['deck_id', 'front_text', 'back_text', 'card_type', 'prompt_json', 'answer_json'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'card_type' => CardType::Recognition->value,
        'search_text' => '',
        'study_status' => CardStudyStatus::New->value,
    ];

    protected static function newFactory(): CardFactory
    {
        return CardFactory::new();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedByActiveDeck(Builder $query, int $userId): Builder
    {
        return $query
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_type' => CardType::class,
            'prompt_json' => 'array',
            'answer_json' => 'array',
            'study_status' => CardStudyStatus::class,
            'source_card_id' => 'integer',
            'source_note_id' => 'integer',
            'source_deck_id' => 'integer',
            'source_template_ord' => 'integer',
            'due_at' => 'datetime',
            'introduced_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'new_queue_position' => 'integer',
            'scheduler_state' => 'array',
        ];
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
