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

    public static function allows(Request $request): bool
    {
        return self::allowsFirstPartySession($request);
    }

    public static function userId(Request $request): mixed
    {
        $user = $request->user();
        $value = $user instanceof User ? $user->getAttribute('convolab_id') : null;

        return is_string($value) ? strtolower(trim($value)) : $value;
    }
}
