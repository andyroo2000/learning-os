<?php

namespace Tests\Feature\Auth\Concerns;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesAuthAccountRateLimitOverrides
{
    /**
     * @param  list<int|string>  $userIdsToClear
     */
    protected function withAuthAccountRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = fn (int|string|null $userId, ?string $ip): string => $testBucket.'|'.AuthAccountRateLimiter::keyFor($limiterName, $userId, $ip);
        $previousServerVariables = $this->serverVariables;
        $defaultLimiter = $this->defaultAuthAccountRateLimiter($limiterName);

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
            // These routes are auth-gated; the per-run ULID prefix keeps defensive anon keys isolated if a future test reaches them.
            foreach ($userIdsToClear as $userId) {
                RateLimiter::clear($testRateLimitKey($userId, $clientIp));
            }

            RateLimiter::for($limiterName, fn (Request $request): Limit => $defaultLimiter->limit($request));
            $this->withServerVariables($previousServerVariables);
        }
    }

    private function defaultAuthAccountRateLimiter(string $limiterName): AuthAccountRateLimiter
    {
        return match ($limiterName) {
            AuthAccountRateLimiter::PROFILE_UPDATE => AuthAccountRateLimiter::forProfileUpdate(),
            AuthAccountRateLimiter::PASSWORD_UPDATE => AuthAccountRateLimiter::forPasswordUpdate(),
            AuthAccountRateLimiter::TOKEN_REVOKE => AuthAccountRateLimiter::forTokenRevoke(),
            default => throw new InvalidArgumentException("Unknown auth account rate limiter [{$limiterName}]."),
        };
    }
}
