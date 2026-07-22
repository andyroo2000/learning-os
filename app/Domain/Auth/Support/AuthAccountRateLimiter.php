<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class AuthAccountRateLimiter
{
    public const PROFILE_UPDATE = 'account-profile-update';

    public const PASSWORD_UPDATE = 'account-password-update';

    public const ACCOUNT_DELETE = 'account-delete';

    public const TOKEN_REVOKE = 'account-token-revoke';

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    public static function forProfileUpdate(): self
    {
        // Profile edits are low-frequency account writes; 30/min leaves room for retrying flaky clients.
        return new self(self::PROFILE_UPDATE, 30);
    }

    public static function forPasswordUpdate(): self
    {
        // Password changes are rare and sensitive; 5/min still covers short retry loops.
        return new self(self::PASSWORD_UPDATE, 5);
    }

    public static function forAccountDelete(): self
    {
        // Account deletion is destructive and should never be part of an automated retry loop.
        return new self(self::ACCOUNT_DELETE, 5);
    }

    public static function forTokenRevoke(): self
    {
        // Token revokes are manual destructive writes; keep them independent from profile/password retries.
        return new self(self::TOKEN_REVOKE, 30);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by($this->key($request));
    }

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
    public static function keyFor(string $limiterName, int|string|null $userId, ?string $ip): string
    {
        // App user IDs are positive integers; zero-like identifiers stay on the defensive network fallback.
        if (self::hasUserKey($userId)) {
            return $limiterName.':user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return $limiterName.':anon:'.$network;
    }

    private static function hasUserKey(int|string|null $userId): bool
    {
        if (is_int($userId)) {
            return $userId > 0;
        }

        if ($userId === null || $userId === '') {
            return false;
        }

        if (is_numeric($userId)) {
            return ctype_digit($userId) && (int) $userId > 0;
        }

        return true;
    }

    private function key(Request $request): string
    {
        return self::keyFor(
            $this->name,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        );
    }
}
