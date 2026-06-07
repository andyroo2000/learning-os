<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardUpdateRateLimiter
{
    public const NAME = 'study-card-update';

    // Saved-card edits can be retried or autosaved by clients, so match the draft autosave headroom.
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

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
    public function keyFor(mixed $userId, ?string $ip): string
    {
        // The route requires auth; the fallback keeps the limiter safe if middleware changes.
        if ($userId !== null) {
            return 'user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return 'anon:'.$network;
    }
}
