<?php

namespace App\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class ConvoLabProxyAuthorization
{
    public static function allows(Request $request, string $ability): bool
    {
        $token = $request->user()?->currentAccessToken();
        $proxyUserEmail = config('services.convolab.proxy_user_email');

        if (
            ! $token instanceof PersonalAccessToken
            || $token->name !== 'convolab-proxy'
            || ! is_string($proxyUserEmail)
            || $proxyUserEmail === ''
            || ! hash_equals(
                Str::lower(trim($proxyUserEmail)),
                Str::lower(trim((string) $request->user()?->email)),
            )
        ) {
            return false;
        }

        return in_array($ability, $token->abilities, true);
    }
}
