<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\ConvoLabVerificationRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ConvoLabVerificationRateLimiterTest extends TestCase
{
    public function test_it_builds_operation_scoped_keys_without_exposing_identity_values(): void
    {
        $userId = '018f47ea-4b37-7f21-8d5a-90e157176b8a';
        $key = ConvoLabVerificationRateLimiter::keyFor(
            ConvoLabVerificationRateLimiter::SEND,
            '  '.strtoupper($userId).'  ',
            '127.0.0.1',
        );

        $this->assertSame(
            ConvoLabVerificationRateLimiter::SEND.':'.hash('sha256', $userId).':127.0.0.1',
            $key,
        );
        $this->assertStringNotContainsString($userId, $key);
        $this->assertSame(
            ConvoLabVerificationRateLimiter::VERIFY.':missing:unknown-ip',
            ConvoLabVerificationRateLimiter::keyFor(
                ConvoLabVerificationRateLimiter::VERIFY,
                null,
                null,
            ),
        );
    }

    public function test_it_uses_separate_bounded_send_and_verify_limits(): void
    {
        $request = Request::create(
            '/api/convolab/auth/verification/send',
            'POST',
            ['token' => str_repeat('a', 64)],
            [],
            [],
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_CONVO_LAB_USER_ID' => '018f47ea-4b37-7f21-8d5a-90e157176b8a',
            ],
        );

        $sendLimiter = ConvoLabVerificationRateLimiter::forSend();
        $send = $sendLimiter->limit($request);
        $sendNetwork = $sendLimiter->networkLimit($request);
        $verifyLimiter = ConvoLabVerificationRateLimiter::forVerify();
        $verify = $verifyLimiter->limit($request);
        $verifyNetwork = $verifyLimiter->networkLimit($request);

        $this->assertSame(6, $send->maxAttempts);
        $this->assertSame(12, $verify->maxAttempts);
        $this->assertStringStartsWith(ConvoLabVerificationRateLimiter::SEND.':', $send->key);
        $this->assertSame(60, $sendNetwork->maxAttempts);
        $this->assertSame(
            ConvoLabVerificationRateLimiter::SEND_NETWORK.':missing:127.0.0.1',
            $sendNetwork->key,
        );
        $this->assertSame(
            ConvoLabVerificationRateLimiter::VERIFY.':'.hash('sha256', str_repeat('a', 64)).':127.0.0.1',
            $verify->key,
        );
        $this->assertSame(120, $verifyNetwork->maxAttempts);
        $this->assertSame(
            ConvoLabVerificationRateLimiter::VERIFY_NETWORK.':missing:127.0.0.1',
            $verifyNetwork->key,
        );
    }
}
