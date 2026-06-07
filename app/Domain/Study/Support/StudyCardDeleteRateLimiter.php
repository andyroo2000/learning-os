<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardDeleteRateLimiter
{
    public const NAME = 'study-card-delete';

    // Idempotent deletes may be retried by mobile sync clients, so keep this roomy.
    private const PER_MINUTE = 60;

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

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
    public function keyFor(mixed $userId, ?string $ip): string
    {
        // Keep deletes in their own bucket so create/import replay cannot spend retry headroom.
        if ($userId !== null) {
            return self::NAME.':user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return self::NAME.':anon:'.$network;
    }
}
