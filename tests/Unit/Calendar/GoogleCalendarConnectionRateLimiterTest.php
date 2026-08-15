<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\Support\GoogleCalendarConnectionRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
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

    public function test_oauth_uses_separate_session_and_network_buckets(): void
    {
        $request = Request::create('/api/study/google-calendar/connect', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->session()->setId('oauth-session');

        $limits = GoogleCalendarConnectionRateLimiter::oauth(GoogleCalendarConnectionRateLimiter::OAUTH_BEGIN, $request);

        $this->assertSame(10, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[1]->maxAttempts);
        $this->assertNotSame($limits[0]->key, $limits[1]->key);
    }

    public function test_oauth_names_a_missing_session_bucket_without_hashing_empty_input(): void
    {
        $request = Request::create('/api/study/google-calendar/connect', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']);

        $limits = GoogleCalendarConnectionRateLimiter::oauth(GoogleCalendarConnectionRateLimiter::OAUTH_BEGIN, $request);

        $this->assertSame('google-calendar-oauth-begin:missing-session', $limits[0]->key);
    }
}
