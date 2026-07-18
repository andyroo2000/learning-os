<?php

namespace App\Domain\Study\Models;

use App\Models\User;
use Database\Factories\StudyVocabVariantGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'target_word',
    'source_sentence',
    'source_context',
    'include_learner_context',
])]
class StudyVocabVariantGroup extends Model
{
    /** @use HasFactory<StudyVocabVariantGroupFactory> */
    use HasFactory, HasUlids;

    protected $table = 'study_vocab_variant_groups';

    protected static function newFactory(): StudyVocabVariantGroupFactory
    {
        return StudyVocabVariantGroupFactory::new();
    }

    protected function casts(): array
    {
        return [
            'include_learner_context' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StudyVocabVariantSentence, $this> */
    public function sentences(): HasMany
    {
        return $this->hasMany(StudyVocabVariantSentence::class, 'variant_group_id');
    }
}
