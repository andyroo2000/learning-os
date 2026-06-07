<?php

namespace Tests\Feature\Flashcards\Concerns;

use App\Domain\Flashcards\Support\DeckRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesDeckRateLimitOverrides
{
    /**
     * @param  array<int, int|string>  $userIdsToClear
     */
    protected function withDeckRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = static fn (mixed $userId, ?string $ip): string => $testBucket.'|'.DeckRateLimiter::keyFor($limiterName, $userId, $ip);
        $defaultLimiter = $this->defaultDeckRateLimiter($limiterName);
        $previousServerVariables = $this->serverVariables;

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

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

            $this->serverVariables = $previousServerVariables;
        }
    }

    private function defaultDeckRateLimiter(string $limiterName): DeckRateLimiter
    {
        return match ($limiterName) {
            DeckRateLimiter::CREATE_NAME => DeckRateLimiter::forCreate(),
            DeckRateLimiter::UPDATE_NAME => DeckRateLimiter::forUpdate(),
            DeckRateLimiter::DELETE_NAME => DeckRateLimiter::forDelete(),
            default => throw new InvalidArgumentException("Unknown deck rate limiter [{$limiterName}]."),
        };
    }
}
