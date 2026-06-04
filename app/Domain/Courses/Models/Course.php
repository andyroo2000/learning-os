<?php

namespace App\Domain\Courses\Models;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Flashcards\Models\Deck;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Models\User;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

// Course ownership and language pair are immutable for Eloquent model updates so future
// sync/media/course-child records can trust those boundaries. Query-builder updates must not mutate them.
// Creation actions must assign user_id from auth context, not request input.
#[Fillable(['title', 'description', 'native_language', 'target_language'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Course $course): void {
            if ($course->isDirty('user_id')) {
                throw new LogicException('Course owner cannot be changed.');
            }

            if ($course->isDirty('native_language') || $course->isDirty('target_language')) {
                throw new LogicException('Course language pair cannot be changed.');
            }
        });
    }

    public function delete(): ?bool
    {
        if ($this->isForceDeleting()) {
            return parent::delete();
        }

        if ($this->trashed()) {
            // Keep retrying DELETE idempotent: SoftDeletes would otherwise refresh deleted_at.
            // Observers that must run on retry should live in the future delete action, not model events.
            return true;
        }

        return parent::delete();
    }

    protected static function newFactory(): CourseFactory
    {
        return CourseFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
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
     * @return HasMany<Deck, $this>
     */
    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }
}
