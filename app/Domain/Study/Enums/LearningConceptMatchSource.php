<?php

namespace App\Domain\Study\Enums;

enum LearningConceptMatchSource: string
{
    case Creation = 'creation';
    case Backfill = 'backfill';
    case Manual = 'manual';
}
