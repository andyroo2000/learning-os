<?php

namespace Tests\Feature\Media\Concerns;

use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Media\Support\MediaAssetRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesMediaRateLimitOverrides
{
    /**
     * @param  list<int|string>  $userIdsToClear
     */
    protected function withMediaRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = fn (mixed $userId, ?string $ip): string => $testBucket.'|'.$this->mediaRateLimitKeyFor($limiterName, $userId, $ip);
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

            $this->restoreMediaRateLimiter($limiterName);
            $this->serverVariables = $previousServerVariables;
        }
    }

    private function mediaRateLimitKeyFor(string $limiterName, mixed $userId, ?string $ip): string
    {
        return match ($limiterName) {
            CardMediaRateLimiter::ATTACH_NAME,
            CardMediaRateLimiter::DETACH_NAME => CardMediaRateLimiter::keyFor($limiterName, $userId, $ip),
            MediaAssetRateLimiter::CREATE_NAME,
            MediaAssetRateLimiter::DELETE_NAME => MediaAssetRateLimiter::keyFor($limiterName, $userId, $ip),
            default => throw new InvalidArgumentException("Unknown media rate limiter [{$limiterName}]."),
        };
    }

    private function restoreMediaRateLimiter(string $limiterName): void
    {
        $limiter = match ($limiterName) {
            CardMediaRateLimiter::ATTACH_NAME => CardMediaRateLimiter::forAttach(),
            CardMediaRateLimiter::DETACH_NAME => CardMediaRateLimiter::forDetach(),
            MediaAssetRateLimiter::CREATE_NAME => MediaAssetRateLimiter::forCreate(),
            MediaAssetRateLimiter::DELETE_NAME => MediaAssetRateLimiter::forDelete(),
            default => throw new InvalidArgumentException("Unknown media rate limiter [{$limiterName}]."),
        };

        RateLimiter::for($limiterName, fn (Request $request): Limit => $limiter->limit($request));
    }
}
