<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Exceptions\ConvoLabSignupException;
use App\Domain\Auth\Models\ConvoLabOAuthIdentity;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClaimConvoLabGoogleInviteAction
{
    public function handle(string $convoLabUserId, string $inviteCode): AdminUserProjection
    {
        $convoLabUserId = Str::lower(trim($convoLabUserId));
        $inviteCode = trim($inviteCode);
        if (! Str::isUuid($convoLabUserId)) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        return DB::transaction(function () use ($convoLabUserId, $inviteCode): AdminUserProjection {
            $account = AdminUserProjection::query()
                ->whereKey($convoLabUserId)
                ->lockForUpdate()
                ->firstOrFail();
            $identity = ConvoLabOAuthIdentity::query()
                ->where('user_id', $account->user_id)
                ->where('provider', ConvoLabOAuthIdentity::GOOGLE_PROVIDER)
                ->lockForUpdate()
                ->first();
            if (! $identity instanceof ConvoLabOAuthIdentity) {
                throw ConvoLabOAuthException::identityNotFound();
            }

            $invite = AdminInviteCode::query()
                ->where('code', $inviteCode)
                ->lockForUpdate()
                ->first();
            if (! $invite instanceof AdminInviteCode) {
                throw ConvoLabSignupException::invalidInvite();
            }

            $claimedByUser = (int) $invite->used_by === (int) $account->user_id
                && is_string($invite->convolab_used_by)
                && hash_equals($convoLabUserId, Str::lower($invite->convolab_used_by));
            if ($identity->access_granted_at !== null) {
                if ($claimedByUser) {
                    return $account;
                }

                throw ConvoLabOAuthException::inviteAlreadyClaimed();
            }
            if ($invite->used_by !== null || $invite->convolab_used_by !== null) {
                throw ConvoLabSignupException::usedInvite();
            }

            $now = now();
            $invite->used_by = $account->user_id;
            $invite->convolab_used_by = $convoLabUserId;
            $invite->used_at = $now;
            $invite->source_system = ConvoLabAccountSource::LEARNING_OS;
            $invite->save();

            $identity->access_granted_at = $now;
            $identity->save();

            return $account;
        }, 3);
    }
}
