<?php

namespace App\Domain\Admin\Support;

use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class AdminMutationRateLimiter
{
    public const USER_DELETE = 'convolab-admin-user-delete';

    public const INVITE_CREATE = 'convolab-admin-invite-create';

    public const INVITE_DELETE = 'convolab-admin-invite-delete';

    public const PRONUNCIATION_DICTIONARY_UPDATE = 'convolab-admin-pronunciation-dictionary-update';

    public static function limit(string $operation, Request $request): Limit
    {
        return Limit::perMinute(30)->by(ConvoLabProfileRateLimiter::key(
            $operation,
            $request->header('X-Convo-Lab-User-Id'),
            $request->ip(),
        ));
    }
}
