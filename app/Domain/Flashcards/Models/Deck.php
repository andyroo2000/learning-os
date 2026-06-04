<?php

namespace App\Domain\Flashcards\Models;

use App\Domain\Courses\Models\Course;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Models\User;
use Database\Factories\DeckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;

// Deck ownership and course scope are treated as immutable after creation; card/media ownership checks rely on that invariant.
#[Fillable(['user_id', 'course_id', 'name', 'description'])]
class Deck extends Model
{
    /** @use HasFactory<DeckFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Deck $deck): void {
            if ($deck->isDirty('user_id')) {
                throw new LogicException('Deck owner cannot be changed.');
            }

            if ($deck->isDirty('course_id')) {
                throw new LogicException('Deck course cannot be changed.');
            }
        });

        static::deleted(function (Deck $deck): void {
            // The cards foreign key is ON DELETE CASCADE, so the database handles force deletes.
            if ($deck->isForceDeleting()) {
                return;
            }

            // cards() scopes to non-deleted rows so independently deleted cards keep their
            // original deleted_at, which future restore semantics will need.
            // FRAGILE: Bulk update skips Card model events for performance. If Card adds deleting
            // observer side effects, switch to per-card deletes here.
            $deletedAt = $deck->deleted_at;

            $deck->cards()->update([
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ]);
        });

        // Restore cascade is intentionally deferred until we track whether each card was
        // deleted independently or as part of its deck.
    }

    // Keep model-level deletes atomic while the cascade still lives in this model observer.
    // In the force-delete path the observer returns early and the FK handles child rows atomically.
    public function delete(): ?bool
    {
        if ($this->isForceDeleting()) {
            return parent::delete();
        }

        if ($this->trashed()) {
            // Keep retrying DELETE idempotent: SoftDeletes would otherwise refresh deleted_at.
            return true;
        }

        return DB::transaction(fn () => parent::delete());
    }

    protected static function newFactory(): DeckFactory
    {
        return DeckFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
