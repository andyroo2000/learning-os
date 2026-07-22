<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCurrentUserAction
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Polymorphic tokens and database sessions have no cascading user foreign key.
            $lockedUser->tokens()->delete();
            DB::table('sessions')->where('user_id', $lockedUser->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $lockedUser->email)->delete();

            // Domain-owned rows use user foreign keys with ON DELETE CASCADE.
            $lockedUser->delete();
        });
    }
}
