<?php

namespace App\Domain\Content\Support;

use App\Http\Support\ConvoLabRequestIdentity;
use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final readonly class ContentCourseRateLimiter
{
    public const UPDATE_NAME = 'content-course-update';

    public const DELETE_NAME = 'content-course-delete';

    private function __construct(
        private int $perMinute,
        private string $operation,
    ) {}

    public static function forUpdate(): self
    {
        return new self(60, self::UPDATE_NAME);
    }

    public static function forDelete(): self
    {
        return new self(30, self::DELETE_NAME);
    }

    public function limit(Request $request): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull(
            ConvoLabRequestIdentity::userId($request),
        );

        return Limit::perMinute($this->perMinute)->by(RateLimitKey::scopedUserOrNetwork(
            $this->operation,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
