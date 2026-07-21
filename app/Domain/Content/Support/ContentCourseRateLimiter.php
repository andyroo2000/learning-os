<?php

namespace App\Domain\Content\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final readonly class ContentCourseRateLimiter
{
    public const CREATE_NAME = 'content-course-create';

    private function __construct(private int $perMinute) {}

    public static function create(): self
    {
        return new self(30);
    }

    public function limit(Request $request): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull($request->header('X-Convo-Lab-User-Id'));

        return Limit::perMinute($this->perMinute)->by(RateLimitKey::scopedUserOrNetwork(
            self::CREATE_NAME,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
