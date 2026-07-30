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
    case WaniKaniReview = 'wanikani_review';
    case Other = 'other';

    public function category(): StudyActivityCategory
    {
        return match ($this) {
            self::CardReview => StudyActivityCategory::Review,
            self::DailyAudio => StudyActivityCategory::Listen,
            self::CardCreation => StudyActivityCategory::Create,
            self::Tv, self::Podcast, self::Reading, self::Other => StudyActivityCategory::Immerse,
            self::Conversation => StudyActivityCategory::Conversation,
            self::WaniKaniReview => StudyActivityCategory::WaniKani,
        };
    }
}
