<?php

namespace App\Support\RateLimiting;

final class ApplicationRateLimiterRegistrar
{
    public static function register(): void
    {
        AuthenticationRateLimiterRegistrar::register();
        AdminContentRateLimiterRegistrar::register();
        FlashcardMediaRateLimiterRegistrar::register();
        StudyRateLimiterRegistrar::register();
        CalendarJapaneseRateLimiterRegistrar::register();
    }
}
