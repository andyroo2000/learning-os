<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyVocabBundleDraftRateLimiter
{
    public const NAME = 'study-vocab-bundle-drafts';

    private const PER_MINUTE = 20;

    public function limit(Request $request, int $perMinute = self::PER_MINUTE): Limit
    {
        return Limit::perMinute($perMinute)->by($this->keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    public function keyFor(mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork(
            self::NAME,
            $userId,
            $ip,
        );
    }
}
