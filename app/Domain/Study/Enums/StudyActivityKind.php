<?php

namespace App\Domain\Study\Enums;

enum StudyActivityKind: string
{
    case CardReview = 'card_review';
    case DailyAudio = 'daily_audio';
    case CardCreation = 'card_creation';
    case Tv = 'tv';
    case Podcast = 'podcast';
    case Reading = 'reading';
    case Conversation = 'conversation';
    case Other = 'other';

    public function category(): StudyActivityCategory
    {
        return match ($this) {
            self::CardReview, self::DailyAudio => StudyActivityCategory::Review,
            self::CardCreation => StudyActivityCategory::Create,
            self::Tv, self::Podcast, self::Reading, self::Conversation, self::Other => StudyActivityCategory::Immerse,
        };
    }
}
