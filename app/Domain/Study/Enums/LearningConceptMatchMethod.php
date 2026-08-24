<?php

namespace App\Domain\Study\Enums;

enum LearningConceptMatchMethod: string
{
    case Exact = 'exact';
    case Surface = 'surface';
    case Classifier = 'classifier';
    case Manual = 'manual';
}
