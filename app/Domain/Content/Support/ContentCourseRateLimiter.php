<?php

namespace App\Domain\Content\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ContentCourseRateLimiter
{
    public const CREATE_NAME = 'content-course-create';

    public static function create(Request $request): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull($request->header('X-Convo-Lab-User-Id'));

        return Limit::perMinute(30)->by(RateLimitKey::scopedUserOrNetwork(
            self::CREATE_NAME,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
