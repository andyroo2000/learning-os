<?php

namespace App\Domain\Calendar\Exceptions;

use RuntimeException;

final class GoogleCalendarProviderException extends RuntimeException
{
    public const RECONNECT_REQUIRED = 'google_calendar_reconnect_required';

    public const UNAVAILABLE = 'google_calendar_provider_unavailable';

    public const SYNC_TOKEN_EXPIRED = 'google_calendar_sync_token_expired';

    public const INVALID_RESPONSE = 'google_calendar_invalid_response';

    public const INVALID_REQUEST = 'google_calendar_invalid_request';

    public function __construct(private readonly string $providerReason)
    {
        parent::__construct('Google Calendar could not be reached safely.');
    }

    public function reason(): string
    {
        return $this->providerReason;
    }
}
