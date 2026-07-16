<?php

namespace App\Domain\Flashcards\Models;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\VariantMetadataLimits;
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
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['deck_id', 'front_text', 'back_text', 'card_type', 'prompt_json', 'answer_json'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings, SoftDeletes;

    public const MAX_VARIANT_ID_LENGTH = VariantMetadataLimits::MAX_ID_LENGTH;

    public const MAX_VARIANT_STAGE = VariantMetadataLimits::MAX_STAGE;

    public const CLIENT_ID_ROUTE_PATTERN = '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})';

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

    protected static function booted(): void
    {
        static::updating(function (Card $card): void {
            if ($card->isDirty([
                'convolab_id',
                'convolab_note_id',
                'convolab_note_created_at',
                'convolab_note_updated_at',
                'convolab_note_source_kind',
                'convolab_note_source_guid',
                'convolab_note_source_notetype_id',
                'convolab_note_raw_fields_json',
                'convolab_note_canonical_json',
            ])) {
                throw new LogicException('Card ConvoLab compatibility metadata cannot be changed.');
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedByActiveDeck(Builder $query, int $userId): Builder
    {
        return $query
            // Own the projection because the deck join also has an id column; apply custom selects after this scope.
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereClientIdentifier(Builder $query, string $clientId): Builder
    {
        $clientId = trim($clientId);

        if (Str::isUuid($clientId)) {
            return $query->where('cards.convolab_id', strtolower($clientId));
        }

        if (Str::isUlid($clientId)) {
            return $query->where('cards.id', CanonicalUlid::normalize($clientId));
        }

        return $query->whereRaw('1 = 0');
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
            'source_queue' => 'integer',
            'source_card_type' => 'integer',
            'source_due' => 'integer',
            'source_interval' => 'integer',
            'source_factor' => 'integer',
            'source_reps' => 'integer',
            'source_lapses' => 'integer',
            'source_left' => 'integer',
            'source_original_due' => 'integer',
            'source_original_deck_id' => 'integer',
            'source_fsrs_json' => 'array',
            'due_at' => 'datetime',
            'introduced_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'new_queue_position' => 'integer',
            'scheduler_state' => 'array',
            'variant_stage' => 'integer',
            'variant_unlocked_at' => 'datetime',
            'convolab_note_created_at' => 'datetime',
            'convolab_note_updated_at' => 'datetime',
            'convolab_note_source_notetype_id' => 'integer',
            'convolab_note_raw_fields_json' => 'array',
            'convolab_note_canonical_json' => 'array',
        ];
    }

    public function clientId(): string
    {
        $convoLabId = $this->getAttribute('convolab_id');

        return is_string($convoLabId) && $convoLabId !== ''
            ? $convoLabId
            : (string) $this->getKey();
    }

    public function clientNoteId(): string
    {
        $convoLabNoteId = $this->getAttribute('convolab_note_id');

        if (is_string($convoLabNoteId) && $convoLabNoteId !== '') {
            return $convoLabNoteId;
        }

        return $this->source_note_id === null
            ? (string) $this->getKey()
            : (string) $this->source_note_id;
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
