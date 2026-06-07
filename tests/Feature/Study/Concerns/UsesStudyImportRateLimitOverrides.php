<?php

namespace Tests\Feature\Study\Concerns;

use App\Domain\Study\Support\StudyImportRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait UsesStudyImportRateLimitOverrides
{
    /**
     * @param  list<int|string>  $userIdsToClear
     */
    protected function withStudyImportRateLimitOverride(
        string $limiterName,
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = fn (mixed $userId, ?string $ip): string => $testBucket.'|'.StudyImportRateLimiter::keyFor($limiterName, $userId, $ip);
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

            $this->restoreStudyImportRateLimiter($limiterName);
            $this->serverVariables = $previousServerVariables;
        }
    }

    private function restoreStudyImportRateLimiter(string $limiterName): void
    {
        $limiter = match ($limiterName) {
            StudyImportRateLimiter::CREATE_NAME => StudyImportRateLimiter::forCreateSession(),
            StudyImportRateLimiter::UPLOAD_NAME => StudyImportRateLimiter::forUpload(),
            StudyImportRateLimiter::COMPLETE_NAME => StudyImportRateLimiter::forComplete(),
            StudyImportRateLimiter::CANCEL_NAME => StudyImportRateLimiter::forCancel(),
            default => throw new InvalidArgumentException("Unknown study import rate limiter [{$limiterName}]."),
        };

        RateLimiter::for($limiterName, fn (Request $request): Limit => $limiter->limit($request));
    }
}
