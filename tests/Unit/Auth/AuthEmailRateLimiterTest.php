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
        $this->assertSame('email:ada@example.com|missing-ip', $limiter->keyFor('ada@example.com', null));
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

        $mobileTokens = $limiter->mobileTokens($request);
        $convoLabLogins = $limiter->convoLabLogins($request);
        $convoLabSignups = $limiter->convoLabSignups($request);
        $mobileRegistrations = $limiter->mobileRegistrations($request);
        [$passwordResetEmail, $passwordResetNetwork] = $limiter->passwordResetLinks($request);
        $passwordResetTokens = $limiter->passwordResetTokens($request);

        $this->assertSame(6, $mobileTokens->maxAttempts);
        $this->assertSame(6, $convoLabLogins->maxAttempts);
        $this->assertSame(6, $convoLabSignups->maxAttempts);
        $this->assertSame(6, $mobileRegistrations->maxAttempts);
        $this->assertSame(6, $passwordResetEmail->maxAttempts);
        $this->assertSame(30, $passwordResetNetwork->maxAttempts);
        $this->assertSame(12, $passwordResetTokens->maxAttempts);

        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $mobileTokens->key);
        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $convoLabLogins->key);
        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $convoLabSignups->key);
        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $mobileRegistrations->key);
        $this->assertSame(
            'password-reset-email-network:email:ada@example.com|ip:127.0.0.1',
            $passwordResetEmail->key,
        );
        $this->assertSame('password-reset-network:ip:127.0.0.1', $passwordResetNetwork->key);
        $this->assertSame('email:ada@example.com|ip:127.0.0.1', $passwordResetTokens->key);
    }
}
