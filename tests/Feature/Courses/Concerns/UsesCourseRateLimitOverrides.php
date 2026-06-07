<?php

namespace Tests\Feature\Courses\Concerns;

use App\Domain\Courses\Support\CourseRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesCourseRateLimitOverrides
{
    /**
     * @param  list<int|string>  $userIdsToClear
     */
    protected function withCourseRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = static fn (mixed $userId, ?string $ip): string => $testBucket.'|'.CourseRateLimiter::keyFor($limiterName, $userId, $ip);
        $defaultLimiter = $this->defaultCourseRateLimiter($limiterName);
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

            RateLimiter::for($limiterName, fn (Request $request): Limit => $defaultLimiter->limit($request));
            $this->withServerVariables($previousServerVariables);
        }
    }

    private function defaultCourseRateLimiter(string $limiterName): CourseRateLimiter
    {
        return match ($limiterName) {
            CourseRateLimiter::CREATE_NAME => CourseRateLimiter::create(),
            CourseRateLimiter::UPDATE_NAME => CourseRateLimiter::update(),
            CourseRateLimiter::DELETE_NAME => CourseRateLimiter::delete(),
            default => throw new InvalidArgumentException("Unknown course rate limiter [{$limiterName}]."),
        };
    }
}
