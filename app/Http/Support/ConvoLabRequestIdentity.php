<?php

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

final class ConvoLabRequestIdentity
{
    public static function allowsFirstPartySession(Request $request): bool
    {
        // Sanctum attaches TransientToken to cookie-authenticated API users. Require its
        // stateful middleware markers too so test or bearer-token identities cannot opt in.
        return $request->attributes->get('sanctum') === true
            && $request->hasSession()
            && $request->user() instanceof User
            && $request->user()->currentAccessToken() instanceof TransientToken;
    }

    public static function allows(Request $request, string $proxyAbility): bool
    {
        return self::allowsFirstPartySession($request)
            || ConvoLabProxyAuthorization::allows($request, $proxyAbility);
    }

    public static function userId(Request $request): mixed
    {
        $value = self::allowsFirstPartySession($request)
            ? $request->user()?->getAttribute('convolab_id')
            : $request->header('X-Convo-Lab-User-Id');

        return is_string($value) ? strtolower(trim($value)) : $value;
    }
}
