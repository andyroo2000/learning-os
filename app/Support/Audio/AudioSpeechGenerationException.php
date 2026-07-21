<?php

namespace App\Support\Audio;

use RuntimeException;
use Throwable;

final class AudioSpeechGenerationException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const RATE_LIMITED = 'rate_limited';

    public const FAILED = 'failed';

    public const INVALID_OUTPUT = 'invalid_output';

    private function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $provider, ?Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, "{$provider} speech generation is unavailable.", $previous);
    }

    public static function rateLimited(string $provider): self
    {
        return new self(self::RATE_LIMITED, "{$provider} is rate limiting speech generation.");
    }

    public static function failed(string $provider, ?Throwable $previous = null): self
    {
        return new self(self::FAILED, "{$provider} failed to generate speech.", $previous);
    }

    public static function invalidOutput(string $provider): self
    {
        return new self(self::INVALID_OUTPUT, "{$provider} returned invalid speech audio.");
    }
}
