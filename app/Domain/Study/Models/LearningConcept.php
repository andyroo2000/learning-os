<?php

namespace App\Domain\Study\Models;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Enums\LearningConceptKind;
use App\Domain\Study\Enums\LearningConceptReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LearningConcept extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'kind' => LearningConceptKind::class,
            'jlpt_level' => 'integer',
            'review_status' => LearningConceptReviewStatus::class,
        ];
    }

    /** @return BelongsToMany<Card, $this> */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_learning_concepts', 'concept_id')
            ->using(CardLearningConcept::class)
            ->withPivot(['match_method', 'match_source', 'confidence', 'classifier_version', 'evidence'])
            ->withTimestamps();
    }
}
