<?php

namespace App\Domain\Content\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ContentAudioScriptRateLimiter
{
    public const GENERATION_NAME = 'content-audio-script-generation';

    public const UPDATE_NAME = 'content-audio-script-update';

    public const MEDIA_READ_NAME = 'content-audio-script-media-read';

    public static function generation(Request $request): Limit
    {
        return self::perMinute($request, self::GENERATION_NAME, 10);
    }

    public static function update(Request $request): Limit
    {
        return self::perMinute($request, self::UPDATE_NAME, 120);
    }

    public static function mediaRead(Request $request): Limit
    {
        return self::perMinute($request, self::MEDIA_READ_NAME, 240);
    }

    private static function perMinute(Request $request, string $name, int $attempts): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull($request->header('X-Convo-Lab-User-Id'));

        return Limit::perMinute($attempts)->by(RateLimitKey::scopedUserOrNetwork(
            $name,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
