<?php

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class ConvoLabAdminAuthorization
{
    public static function allows(Request $request): bool
    {
        if (! ConvoLabRequestIdentity::allowsFirstPartySession($request)) {
            return false;
        }

        $user = $request->user();

        return $user instanceof User
            && $user->adminUserProjection()
                ->where('role', 'admin')
                ->exists();
    }
}
