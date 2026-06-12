<?php

namespace Tests\Unit\Support\RateLimiting;

use App\Support\RateLimiting\RateLimitKey;
use PHPUnit\Framework\TestCase;

class RateLimitKeyTest extends TestCase
{
    public function test_it_builds_scoped_user_or_network_keys(): void
    {
        $this->assertSame('review-create:user:42', RateLimitKey::scopedUserOrNetwork('review-create', 42, '127.0.0.1'));
        $this->assertSame('review-create:user:0', RateLimitKey::scopedUserOrNetwork('review-create', 0, '127.0.0.1'));
        $this->assertSame('review-create:user:user-1', RateLimitKey::scopedUserOrNetwork('review-create', 'user-1', null));
        $this->assertSame('review-create:anon:127.0.0.1', RateLimitKey::scopedUserOrNetwork('review-create', null, '127.0.0.1'));
        $this->assertSame('review-create:anon:127.0.0.1', RateLimitKey::scopedUserOrNetwork('review-create', '', '127.0.0.1'));
        $this->assertSame('review-create:anon:127.0.0.1', RateLimitKey::scopedUserOrNetwork('review-create', false, '127.0.0.1'));
        $this->assertSame('review-create:anon:unknown-ip', RateLimitKey::scopedUserOrNetwork('review-create', null, ''));
    }

    public function test_it_builds_unscoped_user_or_network_keys(): void
    {
        $this->assertSame('user:42', RateLimitKey::userOrNetwork(42, '127.0.0.1'));
        $this->assertSame('user:0', RateLimitKey::userOrNetwork(0, '127.0.0.1'));
        $this->assertSame('user:user-1', RateLimitKey::userOrNetwork('user-1', null));
        $this->assertSame('anon:127.0.0.1', RateLimitKey::userOrNetwork(null, '127.0.0.1'));
        $this->assertSame('anon:127.0.0.1', RateLimitKey::userOrNetwork('', '127.0.0.1'));
        $this->assertSame('anon:127.0.0.1', RateLimitKey::userOrNetwork(false, '127.0.0.1'));
        $this->assertSame('anon:unknown-ip', RateLimitKey::userOrNetwork(null, ''));
    }
}
