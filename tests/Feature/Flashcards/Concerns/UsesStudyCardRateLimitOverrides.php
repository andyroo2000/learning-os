<?php

namespace Tests\Feature\Flashcards\Concerns;

use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesStudyCardRateLimitOverrides
{
    /**
     * @param  array<int, int|string>  $userIdsToClear
     */
    protected function withStudyCardRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = fn (mixed $userId, ?string $ip): string => $testBucket.'|'.$this->studyCardRateLimitKeyFor($limiterName, $userId, $ip);
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

            $this->restoreStudyCardRateLimiter($limiterName);
            $this->serverVariables = $previousServerVariables;
        }
    }

    private function studyCardRateLimitKeyFor(string $limiterName, mixed $userId, ?string $ip): string
    {
        return match ($limiterName) {
            StudyCardCreateRateLimiter::NAME => (new StudyCardCreateRateLimiter)->keyFor($userId, $ip),
            StudyCardUpdateRateLimiter::NAME => (new StudyCardUpdateRateLimiter)->keyFor($userId, $ip),
            StudyCardDeleteRateLimiter::NAME => (new StudyCardDeleteRateLimiter)->keyFor($userId, $ip),
            StudyCardActionRateLimiter::NAME => (new StudyCardActionRateLimiter)->keyFor($userId, $ip),
            default => throw new InvalidArgumentException("Unknown study card rate limiter [{$limiterName}]."),
        };
    }

    private function restoreStudyCardRateLimiter(string $limiterName): void
    {
        switch ($limiterName) {
            case StudyCardCreateRateLimiter::NAME:
                RateLimiter::for(
                    StudyCardCreateRateLimiter::NAME,
                    fn (Request $request): Limit => (new StudyCardCreateRateLimiter)->limit($request),
                );

                return;

            case StudyCardUpdateRateLimiter::NAME:
                RateLimiter::for(
                    StudyCardUpdateRateLimiter::NAME,
                    fn (Request $request): Limit => (new StudyCardUpdateRateLimiter)->limit($request),
                );

                return;

            case StudyCardDeleteRateLimiter::NAME:
                RateLimiter::for(
                    StudyCardDeleteRateLimiter::NAME,
                    fn (Request $request): Limit => (new StudyCardDeleteRateLimiter)->limit($request),
                );

                return;

            case StudyCardActionRateLimiter::NAME:
                RateLimiter::for(
                    StudyCardActionRateLimiter::NAME,
                    fn (Request $request): Limit => (new StudyCardActionRateLimiter)->limit($request),
                );

                return;
        }

        throw new InvalidArgumentException("Unknown study card rate limiter [{$limiterName}].");
    }
}
