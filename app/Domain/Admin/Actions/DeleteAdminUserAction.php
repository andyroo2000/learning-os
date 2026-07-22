<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteAdminUserAction
{
    public function handle(string $actorConvoLabId, string $targetConvoLabId): void
    {
        $actorConvoLabId = ConvoLabUserId::normalize($actorConvoLabId);
        $targetConvoLabId = ConvoLabUserId::normalize($targetConvoLabId);

        if (hash_equals($actorConvoLabId, $targetConvoLabId)) {
            throw AdminMutationException::selfDeletion();
        }

        DB::transaction(function () use ($targetConvoLabId): void {
            // Lock the canonical user first to match account-deletion lock ordering.
            $user = User::query()
                ->where('convolab_id', $targetConvoLabId)
                ->lockForUpdate()
                ->first();
            if (! $user instanceof User) {
                throw AdminMutationException::userNotFound();
            }

            $projection = AdminUserProjection::query()
                ->whereKey($targetConvoLabId)
                ->lockForUpdate()
                ->first();

            if (! $projection instanceof AdminUserProjection) {
                throw AdminMutationException::userNotFound();
            }
            if ($projection->role === 'admin') {
                throw AdminMutationException::adminDeletion();
            }

            AdminInviteCode::query()
                ->where('convolab_used_by', $targetConvoLabId)
                ->update([
                    'used_by' => null,
                    'convolab_used_by' => null,
                    'used_at' => null,
                    'source_system' => ConvoLabAccountSource::LEARNING_OS,
                ]);

            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }
}
