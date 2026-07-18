<?php

namespace App\Domain\Study\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyVocabVariantSentence extends Model
{
    use HasUlids;

    protected $table = 'study_vocab_variant_sentences';

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<StudyVocabVariantGroup, $this> */
    public function variantGroup(): BelongsTo
    {
        return $this->belongsTo(StudyVocabVariantGroup::class, 'variant_group_id');
    }
}
