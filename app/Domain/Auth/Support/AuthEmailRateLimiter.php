<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthEmailRateLimiter
{
    public const MOBILE_TOKENS = 'mobile-tokens';

    public const MOBILE_REGISTRATIONS = 'mobile-registrations';

    public const PASSWORD_RESET_LINKS = 'password-reset-links';

    public const PASSWORD_RESET_TOKENS = 'password-reset-tokens';

    private const STANDARD_PER_MINUTE = 6;

    private const PASSWORD_RESET_TOKEN_PER_MINUTE = 12;

    public function mobileTokens(Request $request): Limit
    {
        return $this->perMinute($request, self::STANDARD_PER_MINUTE);
    }

    public function mobileRegistrations(Request $request): Limit
    {
        return $this->perMinute($request, self::STANDARD_PER_MINUTE);
    }

    public function passwordResetLinks(Request $request): Limit
    {
        return $this->perMinute($request, self::STANDARD_PER_MINUTE);
    }

    public function passwordResetTokens(Request $request): Limit
    {
        return $this->perMinute($request, self::PASSWORD_RESET_TOKEN_PER_MINUTE);
    }

    /**
     * @internal Exposed for focused key-shape tests; route code should use named limiter methods.
     */
    public function keyFor(mixed $email, ?string $ip): string
    {
        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $emailKey = $email !== '' ? 'email:'.$email : 'missing-email';
        $networkKey = $ip !== null && $ip !== '' ? 'ip:'.$ip : 'missing-ip';

        return $emailKey.'|'.$networkKey;
    }

    private function perMinute(Request $request, int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)->by($this->keyFor(
            $request->input('email'),
            $request->ip(),
        ));
    }
}
