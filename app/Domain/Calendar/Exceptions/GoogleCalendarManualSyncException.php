<?php

namespace App\Domain\Calendar\Exceptions;

use RuntimeException;

final class GoogleCalendarManualSyncException extends RuntimeException
{
    public const SETTINGS_REQUIRED = 'settings_required';

    public const SYNC_UNAVAILABLE = 'sync_unavailable';

    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
