<?php

namespace App\Support\RateLimiting;

use App\Domain\Calendar\Support\GoogleCalendarConnectionRateLimiter;
use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class CalendarJapaneseRateLimiterRegistrar
{
    public static function register(): void
    {
        self::registerCalendarAndJapaneseRateLimiters();
    }

    private static function registerCalendarAndJapaneseRateLimiters(): void
    {
        $googleCalendarConnectionRateLimiter = new GoogleCalendarConnectionRateLimiter;
        RateLimiter::for(GoogleCalendarConnectionRateLimiter::NAME, function (Request $request) use ($googleCalendarConnectionRateLimiter): Limit {
            return $googleCalendarConnectionRateLimiter->limit($request);
        });
        RateLimiter::for(GoogleCalendarConnectionRateLimiter::READ_NAME, function (Request $request) use ($googleCalendarConnectionRateLimiter): Limit {
            return $googleCalendarConnectionRateLimiter->read($request);
        });
        foreach ([
            GoogleCalendarConnectionRateLimiter::OAUTH_BEGIN,
            GoogleCalendarConnectionRateLimiter::OAUTH_CALLBACK,
        ] as $operation) {
            RateLimiter::for(
                $operation,
                fn (Request $request): array => GoogleCalendarConnectionRateLimiter::oauth($operation, $request),
            );
        }

        $wanikaniConnectionRateLimiter = JapaneseKnowledgeRateLimiter::forConnection();
        RateLimiter::for(JapaneseKnowledgeRateLimiter::CONNECTION_NAME, function (Request $request) use ($wanikaniConnectionRateLimiter): Limit {
            return $wanikaniConnectionRateLimiter->limit($request);
        });

        $wanikaniSyncRateLimiter = JapaneseKnowledgeRateLimiter::forSync();
        RateLimiter::for(JapaneseKnowledgeRateLimiter::SYNC_NAME, function (Request $request) use ($wanikaniSyncRateLimiter): Limit {
            return $wanikaniSyncRateLimiter->limit($request);
        });

        $knownKanjiManualRateLimiter = JapaneseKnowledgeRateLimiter::forManual();
        RateLimiter::for(JapaneseKnowledgeRateLimiter::MANUAL_NAME, function (Request $request) use ($knownKanjiManualRateLimiter): Limit {
            return $knownKanjiManualRateLimiter->limit($request);
        });
    }
}
