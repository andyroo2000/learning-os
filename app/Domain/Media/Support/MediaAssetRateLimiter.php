<?php

namespace App\Domain\Media\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class MediaAssetRateLimiter
{
    public const CREATE_NAME = 'media-asset-create';

    public const DELETE_NAME = 'media-asset-delete';

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    public static function forCreate(): self
    {
        // Metadata creates may replay with client IDs after reconnect; keep this separate from card writes.
        return new self(self::CREATE_NAME, 60);
    }

    public static function forDelete(): self
    {
        // Deletes are idempotent no-ops for missing assets, but still bound retry storms separately.
        return new self(self::DELETE_NAME, 60);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by($this->key($request));
    }

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
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
