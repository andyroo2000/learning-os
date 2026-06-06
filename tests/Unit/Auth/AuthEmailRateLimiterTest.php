<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\AuthEmailRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AuthEmailRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_typed_email_and_network_keys(): void
    {
        $limiter = new AuthEmailRateLimiter;

        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $limiter->keyFor(' ADA@example.com ', '127.0.0.1'));
        $this->assertSame('missing-email|ip:127.0.0.1', $limiter->keyFor('', '127.0.0.1'));
        $this->assertSame('missing-email|missing-ip', $limiter->keyFor(null, null));
        $this->assertSame('missing-email|missing-ip', $limiter->keyFor(['ada@example.com'], ''));
        $this->assertSame('email:missing-email|ip:127.0.0.1', $limiter->keyFor('missing-email', '127.0.0.1'));
    }

    public function test_it_uses_expected_flow_limits(): void
    {
        $limiter = new AuthEmailRateLimiter;
        $request = Request::create('/api/auth/tokens', 'POST', [
            'email' => ' ADA@example.com ',
        ], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $this->assertSame(6, $limiter->mobileTokens($request)->maxAttempts);
        $this->assertSame(6, $limiter->mobileRegistrations($request)->maxAttempts);
        $this->assertSame(6, $limiter->passwordResetLinks($request)->maxAttempts);
        $this->assertSame(12, $limiter->passwordResetTokens($request)->maxAttempts);
        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $limiter->mobileTokens($request)->key);
    }
}
