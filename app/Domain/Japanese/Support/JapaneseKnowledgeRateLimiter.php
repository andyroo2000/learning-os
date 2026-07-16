<?php

namespace App\Domain\Japanese\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class JapaneseKnowledgeRateLimiter
{
    public const CONNECTION_NAME = 'wanikani-connection-write';

    public const SYNC_NAME = 'wanikani-sync';

    public const MANUAL_NAME = 'known-kanji-manual-write';

    private function __construct(private readonly string $name, private readonly int $perMinute) {}

    public static function forConnection(): self
    {
        return new self(self::CONNECTION_NAME, 10);
    }

    public static function forSync(): self
    {
        return new self(self::SYNC_NAME, 6);
    }

    public static function forManual(): self
    {
        return new self(self::MANUAL_NAME, 60);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by(RateLimitKey::scopedUserOrNetwork(
            $this->name,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
