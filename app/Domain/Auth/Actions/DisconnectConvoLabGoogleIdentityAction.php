<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Models\ConvoLabOAuthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DisconnectConvoLabGoogleIdentityAction
{
    public function handle(string $convoLabUserId): void
    {
        $convoLabUserId = Str::lower(trim($convoLabUserId));
        if (! Str::isUuid($convoLabUserId)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        DB::transaction(function () use ($convoLabUserId): void {
            $user = User::query()
                ->where('convolab_id', $convoLabUserId)
                ->lockForUpdate()
                ->firstOrFail();
            $identity = ConvoLabOAuthIdentity::query()
                ->where('user_id', $user->getKey())
                ->where('provider', ConvoLabOAuthIdentity::GOOGLE_PROVIDER)
                ->lockForUpdate()
                ->first();
            if (! $identity instanceof ConvoLabOAuthIdentity) {
                // Preserve the legacy disconnect contract so clients can distinguish an absent link.
                throw ConvoLabOAuthException::identityNotFound();
            }

            $identity->delete();
        }, 3);
    }
}
