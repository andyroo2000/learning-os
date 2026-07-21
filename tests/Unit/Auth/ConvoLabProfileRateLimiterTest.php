<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConvoLabProfileRateLimiterTest extends TestCase
{
    public function test_identity_keys_are_normalized_hashed_and_operation_scoped(): void
    {
        $identity = 'b4d9208a-c1ef-4bd7-8cb7-103b4079de90';

        $this->assertSame(
            ConvoLabProfileRateLimiter::NAME.':'.hash('sha256', $identity).':127.0.0.1',
            ConvoLabProfileRateLimiter::key(
                ConvoLabProfileRateLimiter::NAME,
                '  '.strtoupper($identity).'  ',
                '127.0.0.1',
            ),
        );
        $this->assertSame(
            ConvoLabProfileRateLimiter::NETWORK_NAME.':missing:127.0.0.1',
            ConvoLabProfileRateLimiter::key(
                ConvoLabProfileRateLimiter::NETWORK_NAME,
                null,
                '127.0.0.1',
            ),
        );
    }

    public function test_missing_and_malformed_identity_values_use_defensive_fallbacks(): void
    {
        $this->assertSame(
            ConvoLabProfileRateLimiter::NAME.':missing:unknown-ip',
            ConvoLabProfileRateLimiter::key(ConvoLabProfileRateLimiter::NAME, null, null),
        );
        $this->assertSame(
            ConvoLabProfileRateLimiter::NAME.':missing:unknown-ip',
            ConvoLabProfileRateLimiter::key(ConvoLabProfileRateLimiter::NAME, ['bad'], ''),
        );
    }

    public function test_profile_and_network_limits_keep_separate_default_buckets(): void
    {
        $request = Request::create('/api/convolab/auth/me', 'PATCH', server: [
            'HTTP_X_CONVO_LAB_USER_ID' => 'b4d9208a-c1ef-4bd7-8cb7-103b4079de90',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $identity = ConvoLabProfileRateLimiter::limit($request);
        $network = ConvoLabProfileRateLimiter::networkLimit($request);

        $this->assertSame(30, $identity->maxAttempts);
        $this->assertSame(120, $network->maxAttempts);
        $this->assertStringStartsWith(ConvoLabProfileRateLimiter::NAME.':', $identity->key);
        $this->assertStringStartsWith(ConvoLabProfileRateLimiter::NETWORK_NAME.':', $network->key);
    }
}
