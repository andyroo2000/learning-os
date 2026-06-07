<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardDraftRetryRateLimiter
{
    public const NAME = 'study-card-draft-retry';

    // Manual retries can burst after transient generation failures, but keep them isolated from create/autosave traffic.
    private const PER_MINUTE = 30;

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
            return 'user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return 'anon:'.$network;
    }
}
