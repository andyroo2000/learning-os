<?php

namespace App\Domain\Flashcards\Enums;

enum CardSelectionPolicy: string
{
    case Standard = 'standard';
    case Sprinkled = 'sprinkled';
    case ReviewSoon = 'review_soon';
}
