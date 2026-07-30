<?php

namespace App\Domain\Study\Enums;

enum StudyActivityCategory: string
{
    case Review = 'review';
    case Listen = 'listen';
    case Create = 'create';
    case Immerse = 'immerse';
    case Conversation = 'conversation';
    case WaniKani = 'wanikani';
}
