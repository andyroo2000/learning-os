<?php

namespace App\Domain\Study\Enums;

enum NewCardLane: string
{
    case Standard = 'standard';
    case LessonFollowup = 'lesson_followup';
    case WaniKani = 'wanikani';
}
