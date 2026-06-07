<?php

namespace App\Domain\Media\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class CardMediaRateLimiter
{
    public const ATTACH_NAME = 'card-media-attach';

    public const DETACH_NAME = 'card-media-detach';

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    public static function forAttach(): self
    {
        // Attach replay is idempotent; 60/min allows mobile backlog repair without sharing card-write buckets.
        return new self(self::ATTACH_NAME, 60);
    }

    public static function forDetach(): self
    {
        // Detach is also retry-safe and low-frequency, so keep a separate roomy relation-write bucket.
        return new self(self::DETACH_NAME, 60);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by($this->key($request));
    }

    public static function keyFor(string $limiterName, mixed $userId, ?string $ip): string
    {
        // Auth normally rejects anonymous requests first; this fallback bounds unexpected IP-less traffic.
        if (is_int($userId) || is_string($userId)) {
            return $limiterName.':user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return $limiterName.':anon:'.$network;
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
