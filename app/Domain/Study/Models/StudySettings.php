<?php

namespace App\Domain\Study\Models;

use App\Models\User;
use Database\Factories\StudySettingsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['new_cards_per_day', 'lesson_batch_size', 'review_time_budget_minutes'])]
class StudySettings extends Model
{
    /** @use HasFactory<StudySettingsFactory> */
    use HasFactory;

    public const DEFAULT_NEW_CARDS_PER_DAY = 20;

    public const MAX_NEW_CARDS_PER_DAY = 1000;

    public const DEFAULT_LESSON_BATCH_SIZE = 5;

    public const MIN_LESSON_BATCH_SIZE = 3;

    public const MAX_LESSON_BATCH_SIZE = 10;

    public const DEFAULT_REVIEW_TIME_BUDGET_MINUTES = 90;

    public const MIN_REVIEW_TIME_BUDGET_MINUTES = 15;

    public const MAX_REVIEW_TIME_BUDGET_MINUTES = 240;

    protected static function booted(): void
    {
        static::updating(function (StudySettings $settings): void {
            if ($settings->isDirty('user_id')) {
                throw new LogicException('Study settings owner cannot be changed.');
            }
        });
    }

    protected static function newFactory(): StudySettingsFactory
    {
        return StudySettingsFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'new_cards_per_day' => 'integer',
            'lesson_batch_size' => 'integer',
            'review_time_budget_minutes' => 'integer',
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
