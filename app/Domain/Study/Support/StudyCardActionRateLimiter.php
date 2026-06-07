<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class StudyCardActionRateLimiter
{
    public const NAME = 'study-card-action';

    // 120/min matches saved-card edits; manual actions have similar client retry behavior.
    private const PER_MINUTE = 120;

    public function limit(Request $request): Limit
    {
        return Limit::perMinute(self::PER_MINUTE)->by($this->key($request));
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
        // Auth normally rejects anonymous requests first; this shared fallback only bounds unexpected IP-less traffic.
        if ($userId !== null) {
            return self::NAME.':user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return self::NAME.':anon:'.$network;
    }
}
