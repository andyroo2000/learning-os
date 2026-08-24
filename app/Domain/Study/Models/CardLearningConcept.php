<?php

namespace App\Domain\Study\Models;

use App\Domain\Study\Enums\LearningConceptMatchMethod;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class CardLearningConcept extends Pivot
{
    protected $table = 'card_learning_concepts';

    protected function casts(): array
    {
        return [
            'match_method' => LearningConceptMatchMethod::class,
            'match_source' => LearningConceptMatchSource::class,
            'confidence' => 'decimal:4',
            'evidence' => 'array',
        ];
    }
}
