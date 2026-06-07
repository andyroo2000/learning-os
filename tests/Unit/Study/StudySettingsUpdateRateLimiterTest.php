<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudySettingsUpdateRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudySettingsUpdateRateLimiter;

        $this->assertSame('study-settings-update:user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('study-settings-update:user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('study-settings-update:anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('study-settings-update:anon:unknown-ip', $limiter->keyFor(null, ''));
        $this->assertSame('study-settings-update:anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('study-settings-update:anon:192.0.2.10', $limiter->keyFor(null, '192.0.2.10'));
        $this->assertSame('study-settings-update:user:user-1', $limiter->keyFor('user-1', ''));
        $this->assertSame('study-settings-update:user:str-id', $limiter->keyFor('str-id', ''));
    }

    public function test_it_uses_30_attempts_per_minute_by_default(): void
    {
        $limiter = new StudySettingsUpdateRateLimiter;
        $request = Request::create('/api/study/settings', 'PATCH', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-settings-update:user:42', $limit->key);
    }
}
