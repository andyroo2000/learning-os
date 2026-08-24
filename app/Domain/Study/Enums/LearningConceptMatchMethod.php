<?php

namespace App\Domain\Study\Enums;

enum LearningConceptMatchMethod: string
{
    case Exact = 'exact';
    case Classifier = 'classifier';
    case Backfill = 'backfill';
    case Manual = 'manual';
}
