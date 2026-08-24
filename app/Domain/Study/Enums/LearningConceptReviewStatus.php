<?php

namespace App\Domain\Study\Enums;

enum LearningConceptReviewStatus: string
{
    case Seed = 'seed';
    case Draft = 'draft';
    case NeedsReview = 'needs_review';
}
