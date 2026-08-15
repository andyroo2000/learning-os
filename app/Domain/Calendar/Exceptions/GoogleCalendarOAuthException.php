<?php

namespace App\Domain\Calendar\Exceptions;

use RuntimeException;

final class GoogleCalendarOAuthException extends RuntimeException
{
    public function __construct(private readonly string $oauthReason)
    {
        parent::__construct('Google Calendar authorization could not be completed.');
    }

    public function reason(): string
    {
        return $this->oauthReason;
    }
}
