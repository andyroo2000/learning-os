<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Models\ConvoLabEmailVerificationToken;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class IssueConvoLabVerificationTokenAction
{
    public function handle(int $userId): ?string
    {
        return DB::transaction(function () use ($userId): ?string {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existingTokens = ConvoLabEmailVerificationToken::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->get();
            $account = AdminUserProjection::query()
                ->where('user_id', $userId)
                ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
                ->lockForUpdate()
                ->first();
            if (! $account instanceof AdminUserProjection) {
                ConvoLabEmailVerificationToken::query()->whereKey($existingTokens->modelKeys())->delete();

                return null;
            }
            if ($account->email_verified) {
                return null;
            }

            ConvoLabEmailVerificationToken::query()->whereKey($existingTokens->modelKeys())->delete();

            $token = bin2hex(random_bytes(32));
            $record = new ConvoLabEmailVerificationToken;
            $record->user_id = $userId;
            $record->token_hash = hash('sha256', $token);
            $record->expires_at = now()->addDay();
            $record->save();

            return $token;
        });
    }
}
