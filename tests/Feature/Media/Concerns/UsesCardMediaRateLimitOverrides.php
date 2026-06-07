<?php

namespace Tests\Feature\Media\Concerns;

use App\Domain\Media\Support\CardMediaRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesCardMediaRateLimitOverrides
{
    /**
     * @param  list<int|string>  $userIdsToClear
     */
    protected function withCardMediaRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = fn (mixed $userId, ?string $ip): string => $testBucket.'|'.CardMediaRateLimiter::keyFor($limiterName, $userId, $ip);
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

            $this->restoreCardMediaRateLimiter($limiterName);
            $this->serverVariables = $previousServerVariables;
        }
    }

    private function restoreCardMediaRateLimiter(string $limiterName): void
    {
        $limiter = match ($limiterName) {
            CardMediaRateLimiter::ATTACH_NAME => CardMediaRateLimiter::forAttach(),
            CardMediaRateLimiter::DETACH_NAME => CardMediaRateLimiter::forDetach(),
            default => throw new InvalidArgumentException("Unknown card media rate limiter [{$limiterName}]."),
        };

        RateLimiter::for($limiterName, fn (Request $request): Limit => $limiter->limit($request));
    }
}
