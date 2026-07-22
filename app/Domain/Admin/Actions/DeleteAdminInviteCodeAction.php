<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminInviteCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeleteAdminInviteCodeAction
{
    public function handle(string $inviteId): void
    {
        if (! Str::isUuid($inviteId)) {
            throw AdminMutationException::inviteNotFound();
        }

        DB::transaction(function () use ($inviteId): void {
            $invite = AdminInviteCode::query()
                ->whereKey(Str::lower($inviteId))
                ->lockForUpdate()
                ->first();

            if (! $invite instanceof AdminInviteCode) {
                throw AdminMutationException::inviteNotFound();
            }
            if ($invite->used_by !== null || $invite->convolab_used_by !== null) {
                throw AdminMutationException::usedInvite();
            }

            DB::table('admin_invite_code_tombstones')->insertOrIgnore([
                'invite_code_id' => Str::lower($inviteId),
                'deleted_at' => now(),
            ]);
            $invite->delete();
        });
    }
}
