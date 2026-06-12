<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardDraftAutosaveRateLimiter
{
    public const NAME = 'study-card-draft-autosave';

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
        return RateLimitKey::userOrNetwork($userId, $ip);
    }
}
