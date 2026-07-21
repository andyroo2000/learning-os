<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ConvoLabVerificationRateLimiter
{
    public const SEND = 'convolab-verification-send';

    public const VERIFY = 'convolab-verification-verify';

    public const VERIFY_NETWORK = 'convolab-verification-verify-network';

    public static function forSend(): self
    {
        return new self(self::SEND, 6, true);
    }

    public static function forVerify(): self
    {
        return new self(self::VERIFY, 12, false);
    }

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
        private readonly bool $useUserHeader,
    ) {}

    public function limit(Request $request): Limit
    {
        $identity = $this->useUserHeader
            ? $request->header('X-Convo-Lab-User-Id')
            : $request->route('token');

        return Limit::perMinute($this->perMinute)->by(self::keyFor(
            $this->name,
            is_string($identity) ? $identity : null,
            $request->ip(),
        ));
    }

    public function networkLimit(Request $request): Limit
    {
        return Limit::perMinute(120)->by(self::keyFor(
            self::VERIFY_NETWORK,
            null,
            $request->ip(),
        ));
    }

    public static function keyFor(string $name, ?string $identity, ?string $ip): string
    {
        $identity = $identity === null ? '' : strtolower(trim($identity));
        $identityKey = $identity === '' ? 'missing' : hash('sha256', $identity);
        $networkKey = $ip === null || $ip === '' ? 'unknown-ip' : $ip;

        return $name.':'.$identityKey.':'.$networkKey;
    }
}
