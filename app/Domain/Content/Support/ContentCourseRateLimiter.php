<?php

namespace App\Domain\Content\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final readonly class ContentCourseRateLimiter
{
    public const CREATE_NAME = 'content-course-create';

    public const GENERATION_NAME = 'content-course-generation';

    public const RESET_NAME = 'content-course-generation-reset';

    private function __construct(
        private int $perMinute,
        private string $operation,
    ) {}

    public static function forCreate(): self
    {
        return new self(30, self::CREATE_NAME);
    }

    public static function forGeneration(): self
    {
        return new self(10, self::GENERATION_NAME);
    }

    public static function forReset(): self
    {
        return new self(10, self::RESET_NAME);
    }

    public function limit(Request $request): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull($request->header('X-Convo-Lab-User-Id'));

        return Limit::perMinute($this->perMinute)->by(RateLimitKey::scopedUserOrNetwork(
            $this->operation,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
