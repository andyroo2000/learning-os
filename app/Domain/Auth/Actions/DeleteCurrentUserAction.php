<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class DeleteCurrentUserAction
{
    public function handle(User $user, string $currentPassword): void
    {
        DB::transaction(function () use ($currentPassword, $user): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! Hash::check($currentPassword, $lockedUser->password)) {
                throw new InvalidCurrentPasswordException;
            }

            // Polymorphic tokens and database sessions have no cascading user foreign key.
            $lockedUser->tokens()->delete();
            DB::table('sessions')->where('user_id', $lockedUser->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $lockedUser->email)->delete();

            // Domain-owned rows use user foreign keys with ON DELETE CASCADE.
            $lockedUser->delete();
        });
    }
}
