<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AuthAccountRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_operation_scoped_keys(): void
    {
        $this->assertSame('account-profile-update:user:42', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, 42, '127.0.0.1'));
        $this->assertSame('account-profile-update:user:42', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, 42, '192.0.2.10'));
        $this->assertSame('account-profile-update:anon:unknown-ip', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, null, null));
        $this->assertSame('account-profile-update:anon:unknown-ip', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, null, ''));
        $this->assertSame('account-profile-update:anon:127.0.0.1', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, null, '127.0.0.1'));
        $this->assertSame('account-profile-update:anon:127.0.0.1', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, -1, '127.0.0.1'));
        $this->assertSame('account-profile-update:anon:127.0.0.1', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, '-1', '127.0.0.1'));
        $this->assertSame('account-profile-update:anon:127.0.0.1', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, 0, '127.0.0.1'));
        $this->assertSame('account-profile-update:anon:127.0.0.1', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, '0', '127.0.0.1'));
        $this->assertSame('account-profile-update:anon:127.0.0.1', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, '1.5', '127.0.0.1'));
        $this->assertSame('account-profile-update:user:42', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, '42', ''));
        $this->assertSame('account-profile-update:user:str-id', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PROFILE_UPDATE, 'str-id', ''));
        $this->assertSame('account-password-update:user:42', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::PASSWORD_UPDATE, 42, '127.0.0.1'));
        $this->assertSame('account-token-revoke:user:42', AuthAccountRateLimiter::keyFor(AuthAccountRateLimiter::TOKEN_REVOKE, 42, '127.0.0.1'));
    }

    public function test_profile_update_uses_30_attempts_per_minute_by_default(): void
    {
        $limit = AuthAccountRateLimiter::forProfileUpdate()->limit($this->requestWithUserId(42, 'PUT', '/api/me'));

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('account-profile-update:user:42', $limit->key);
    }

    public function test_password_update_uses_5_attempts_per_minute_by_default(): void
    {
        $limit = AuthAccountRateLimiter::forPasswordUpdate()->limit($this->requestWithUserId(42, 'PUT', '/api/me/password'));

        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('account-password-update:user:42', $limit->key);
    }

    public function test_token_revoke_uses_30_attempts_per_minute_by_default(): void
    {
        $limit = AuthAccountRateLimiter::forTokenRevoke()->limit($this->requestWithUserId(42, 'DELETE', '/api/auth/tokens/current'));

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('account-token-revoke:user:42', $limit->key);
    }

    private function requestWithUserId(int $userId, string $method, string $uri): Request
    {
        $request = Request::create($uri, $method, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class($userId)
        {
            public function __construct(private readonly int $userId) {}

            public function getAuthIdentifier(): int
            {
                return $this->userId;
            }
        });

        return $request;
    }
}
