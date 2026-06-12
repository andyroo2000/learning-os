<?php

namespace App\Domain\Courses\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class CourseRateLimiter
{
    public const CREATE_NAME = 'course-create';

    public const UPDATE_NAME = 'course-update';

    public const DELETE_NAME = 'course-delete';

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    public static function create(): self
    {
        // Course creation is user-driven but retryable for offline clients; 60/min leaves generous replay headroom.
        return new self(self::CREATE_NAME, 60);
    }

    public static function update(): self
    {
        // Course metadata edits are manual but can be retried by sync clients; 60/min keeps updates separate from creates.
        return new self(self::UPDATE_NAME, 60);
    }

    public static function delete(): self
    {
        // Course deletes are low-frequency gestures; 30/min still tolerates client retry loops.
        return new self(self::DELETE_NAME, 30);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by($this->key($request));
    }

    public static function keyFor(string $limiterName, mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork($limiterName, $userId, $ip);
    }

    private function key(Request $request): string
    {
        return self::keyFor(
            $this->name,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        );
    }
}
