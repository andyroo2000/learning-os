<?php

namespace App\Domain\Japanese\Exceptions;

use RuntimeException;

final class WaniKaniApiException extends RuntimeException
{
    public static function invalidToken(): self
    {
        return new self('WaniKani rejected this API token.', 401);
    }

    public static function unavailable(): self
    {
        return new self('WaniKani is temporarily unavailable.', 503);
    }

    public static function invalidResponse(): self
    {
        return new self('WaniKani returned an unexpected response.', 502);
    }
}
