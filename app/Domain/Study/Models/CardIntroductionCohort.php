<?php

namespace App\Domain\Study\Models;

use App\Domain\Flashcards\Enums\CardSourceKind;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardIntroductionCohort extends Model
{
    use HasUlids;

    public const MAX_LABEL_LENGTH = 120;

    public const MAX_SOURCE_REFERENCE_LENGTH = 255;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => CardSourceKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Card, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'introduction_cohort_id');
    }
}
