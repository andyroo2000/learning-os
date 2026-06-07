<?php

namespace Tests\Feature\Flashcards\Concerns;

use App\Domain\Flashcards\Support\DeckRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

trait UsesDeckRateLimitOverrides
{
    /**
     * @param  array<int, mixed>  $userIdsToClear
     */
    protected function withDeckRateLimitOverride(
        string $limiterName,
        DeckRateLimiter $defaultLimiter,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = static fn (mixed $userId, ?string $ip): string => $testBucket.'|'.DeckRateLimiter::keyFor($limiterName, $userId, $ip);

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

        try {
            RateLimiter::for($limiterName, function (Request $request) use ($perMinute, $testRateLimitKey): Limit {
                return Limit::perMinute($perMinute)->by($testRateLimitKey(
                    $request->user()?->getAuthIdentifier(),
                    $request->ip(),
                ));
            });

            $callback();
        } finally {
            foreach ($userIdsToClear as $userId) {
                RateLimiter::clear($testRateLimitKey($userId, $clientIp));
            }

            RateLimiter::for($limiterName, function (Request $request) use ($defaultLimiter): Limit {
                return $defaultLimiter->limit($request);
            });
        }
    }
}
