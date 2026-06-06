<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use PHPUnit\Framework\TestCase;

class StudyCardCreateRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudyCardCreateRateLimiter;

        $this->assertSame('42|127.0.0.1', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('missing-user|unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('user-1|unknown-ip', $limiter->keyFor('user-1', ''));
    }
}
