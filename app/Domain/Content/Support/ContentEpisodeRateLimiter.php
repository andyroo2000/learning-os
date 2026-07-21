<?php

namespace App\Domain\Content\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final readonly class ContentEpisodeRateLimiter
{
    public const CREATE_NAME = 'content-episode-create';

    public const UPDATE_NAME = 'content-episode-update';

    public const DELETE_NAME = 'content-episode-delete';

    private function __construct(
        private string $name,
        private int $perMinute,
    ) {}

    public static function create(): self
    {
        return new self(self::CREATE_NAME, 60);
    }

    public static function update(): self
    {
        return new self(self::UPDATE_NAME, 60);
    }

    public static function delete(): self
    {
        return new self(self::DELETE_NAME, 30);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by(self::keyFor(
            $this->name,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    private static function keyFor(string $limiterName, mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork($limiterName, $userId, $ip);
    }
}
