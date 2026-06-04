<?php

namespace App\Domain\Auth\Support;

use DateTimeInterface;

class MobileTokenExpiration
{
    public function expiresAt(): ?DateTimeInterface
    {
        $expirationMinutes = config('sanctum.expiration');

        if (! is_int($expirationMinutes) && ! ctype_digit((string) $expirationMinutes)) {
            return null;
        }

        $expirationMinutes = (int) $expirationMinutes;

        if ($expirationMinutes < 1) {
            return null;
        }

        return now()->addMinutes($expirationMinutes);
    }
}
