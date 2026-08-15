<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\Support\GoogleCalendarConnectionRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class GoogleCalendarConnectionRateLimiterTest extends TestCase
{
    public function test_disconnect_uses_a_typed_per_user_bucket(): void
    {
        $request = Request::create('/api/study/google-calendar', 'DELETE', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = (new GoogleCalendarConnectionRateLimiter)->limit($request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('google-calendar-connection-write:user:42', $limit->key);
    }
}
