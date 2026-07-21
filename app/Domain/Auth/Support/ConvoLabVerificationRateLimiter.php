<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ConvoLabVerificationRateLimiter
{
    public const SEND = 'convolab-verification-send';

    public const SEND_NETWORK = 'convolab-verification-send-network';

    public const VERIFY = 'convolab-verification-verify';

    public const VERIFY_NETWORK = 'convolab-verification-verify-network';

    public static function forSend(): self
    {
        return new self(self::SEND, 6, true, self::SEND_NETWORK, 60);
    }

    public static function forVerify(): self
    {
        return new self(self::VERIFY, 12, false, self::VERIFY_NETWORK, 120);
    }

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
        private readonly bool $useUserHeader,
        private readonly string $networkName,
        private readonly int $networkPerMinute,
    ) {}

    public function limit(Request $request): Limit
    {
        $identity = $this->useUserHeader
            ? $request->header('X-Convo-Lab-User-Id')
            : $request->input('token');

        return Limit::perMinute($this->perMinute)->by(self::keyFor(
            $this->name,
            is_string($identity) ? $identity : null,
            $request->ip(),
        ));
    }

    public function networkLimit(Request $request): Limit
    {
        return Limit::perMinute($this->networkPerMinute)->by(self::keyFor(
            $this->networkName,
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
