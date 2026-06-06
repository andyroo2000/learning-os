<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardCreateRateLimiter
{
    public const NAME = 'study-card-create';

    private const PER_MINUTE = 120;

    public function limit(Request $request, int $perMinute = self::PER_MINUTE): Limit
    {
        return Limit::perMinute($perMinute)->by($this->key($request));
    }

    private function key(Request $request): string
    {
        return $this->keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        );
    }

    public function keyFor(mixed $userId, ?string $ip): string
    {
        // The route requires auth; the fallback keeps the limiter safe if middleware changes.
        if ($userId !== null) {
            // Authenticated creation quotas are intentionally user-scoped across network changes.
            return 'user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return 'anon:'.$network;
    }
}
