<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Support\StudyActivitySessionRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyActivitySessionRateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rate_limits_session_writes_by_user(): void
    {
        $limiter = new StudyActivitySessionRateLimiter;
        $user = $this->signIn();
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $key = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);
            RateLimiter::for(
                StudyActivitySessionRateLimiter::NAME,
                fn (Request $request): Limit => Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor(
                        $request->user()?->getAuthIdentifier(),
                        $request->ip(),
                    ),
                ),
            );

            $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
                ->assertUnprocessable();
            $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
                ->assertUnprocessable();
            $this->postJson('/api/study/activity-sessions/batch', ['sessions' => []])
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '2')
                ->assertHeader('X-RateLimit-Remaining', '0');
        } finally {
            RateLimiter::clear($key);
            RateLimiter::for(
                StudyActivitySessionRateLimiter::NAME,
                fn (Request $request): Limit => $limiter->limit($request),
            );
        }
    }
}
