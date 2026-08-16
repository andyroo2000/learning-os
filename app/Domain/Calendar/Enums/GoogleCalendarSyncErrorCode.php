<?php

namespace App\Domain\Calendar\Enums;

enum GoogleCalendarSyncErrorCode: string
{
    case ReconnectRequired = 'reconnect_required';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidProviderResponse = 'invalid_provider_response';
    case SyncFailed = 'sync_failed';
}
