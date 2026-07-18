<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;
use Throwable;

class StudyPreviewMediaGenerationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $httpStatus,
        ?Throwable $previous = null,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function providerUnavailable(string $provider, ?Throwable $previous = null): self
    {
        return new self("{$provider} preview generation is unavailable.", 503, $previous);
    }

    public static function providerRateLimited(string $provider): self
    {
        return new self("{$provider} is rate limiting preview generation. Please try again shortly.", 429);
    }

    public static function spendLimitExceeded(int $retryAfterSeconds): self
    {
        return new self(
            'Study media generation rate limit exceeded. Please try again shortly.',
            429,
            null,
            max(1, $retryAfterSeconds),
        );
    }

    public static function providerFailed(string $provider, ?Throwable $previous = null): self
    {
        return new self("{$provider} failed to generate preview media.", 502, $previous);
    }

    public static function invalidProviderOutput(string $provider): self
    {
        return new self("{$provider} returned invalid preview media.", 502);
    }

    public static function storageFailed(?Throwable $previous = null): self
    {
        return new self('Generated preview media could not be stored.', 500, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(): array
    {
        if ($this->retryAfterSeconds === null) {
            return [];
        }

        return [
            'Retry-After' => (string) $this->retryAfterSeconds,
            'X-RateLimit-Limit' => '10',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + $this->retryAfterSeconds),
        ];
    }
}
