<?php

namespace App\Domain\Courses\Models;

use App\Domain\Courses\Enums\CourseStatus;
use App\Models\User;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

// Course ownership is immutable so future sync/media/course-child records can trust the owner boundary.
// Creation actions must source user_id from auth context; fillable exists for trusted model construction.
#[Fillable(['user_id', 'title', 'description', 'status', 'native_language', 'target_language'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Course $course): void {
            if ($course->isDirty('user_id')) {
                throw new LogicException('Course owner cannot be changed.');
            }
        });
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
            'user_id' => 'integer',
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
}
