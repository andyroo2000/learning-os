<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\InvalidConvoLabVerificationTokenException;
use App\Domain\Auth\Models\ConvoLabEmailVerificationToken;
use App\Domain\Auth\Support\ConvoLabAdminEmails;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VerifyConvoLabEmailAction
{
    public function handle(string $token): AdminUserProjection
    {
        $tokenHash = hash('sha256', $token);
        $userId = ConvoLabEmailVerificationToken::query()
            ->where('token_hash', $tokenHash)
            ->value('user_id');

        $account = $userId === null ? null : DB::transaction(function () use ($tokenHash, $userId): ?AdminUserProjection {
            $user = User::query()->lockForUpdate()->find($userId);
            $record = ConvoLabEmailVerificationToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ConvoLabEmailVerificationToken || ! $user instanceof User) {
                return null;
            }
            if ($record->expires_at->isPast()) {
                $record->delete();

                return null;
            }

            $account = AdminUserProjection::query()
                ->where('user_id', $record->user_id)
                ->lockForUpdate()
                ->first();
            if (! $account instanceof AdminUserProjection) {
                $record->delete();

                return null;
            }
            if ($record->consumed_at !== null) {
                return $account->email_verified && $user->email_verified_at !== null
                    ? $account
                    : null;
            }

            $verifiedAt = now();
            $account->email_verified = true;
            $account->email_verified_at = $verifiedAt;
            $account->role = ConvoLabAdminEmails::contains($account->email) ? 'admin' : 'user';
            $account->updated_at = $verifiedAt;
            $account->save();
            $user->email_verified_at = $verifiedAt;
            $user->save();
            $record->consumed_at = $verifiedAt;
            $record->save();

            return $account->refresh();
        });

        if (! $account instanceof AdminUserProjection) {
            throw new InvalidConvoLabVerificationTokenException;
        }

        return $account;
    }
}
