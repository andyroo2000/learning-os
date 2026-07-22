<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\ConvoLabAccountSecurityRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ConvoLabAccountSecurityRateLimiterTest extends TestCase
{
    public function test_it_builds_target_and_network_limits_for_each_operation(): void
    {
        $request = Request::create('/api/convolab/auth/me/password', 'PUT', server: [
            'HTTP_X_CONVO_LAB_USER_ID' => ' 550E8400-E29B-41D4-A716-446655440000 ',
            'REMOTE_ADDR' => '192.0.2.10',
        ]);

        foreach ([
            ConvoLabAccountSecurityRateLimiter::PASSWORD_UPDATE,
            ConvoLabAccountSecurityRateLimiter::ACCOUNT_DELETE,
        ] as $operation) {
            [$target, $network] = ConvoLabAccountSecurityRateLimiter::limits($operation, $request);

            $this->assertSame(5, $target->maxAttempts);
            $this->assertSame(60, $target->decaySeconds);
            $this->assertStringStartsWith($operation.':', $target->key);
            $this->assertStringEndsWith(':unknown-ip', $target->key);
            $this->assertSame(60, $network->maxAttempts);
            $this->assertSame(60, $network->decaySeconds);
            $this->assertStringStartsWith($operation.ConvoLabAccountSecurityRateLimiter::NETWORK_SUFFIX.':', $network->key);
            $this->assertStringEndsWith(':192.0.2.10', $network->key);
            $this->assertNotSame($target->key, $network->key);
        }
    }
}
