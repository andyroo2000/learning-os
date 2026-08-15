<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ConnectGoogleCalendarAction
{
    public function handle(int $userId, GoogleCalendarOAuthGrant $grant): void
    {
        try {
            DB::transaction(function () use ($userId, $grant): void {
                $conflict = GoogleCalendarConnection::query()
                    ->where('provider_account_id', $grant->providerAccountId)
                    ->where('user_id', '!=', $userId)
                    ->lockForUpdate()
                    ->exists();
                if ($conflict) {
                    throw new GoogleCalendarOAuthException('account_conflict');
                }

                $connection = GoogleCalendarConnection::query()
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                $sameAccount = $connection?->provider_account_id === $grant->providerAccountId;
                $refreshToken = $grant->refreshToken
                    ?? ($sameAccount ? $connection?->refresh_token : null);
                if ($refreshToken === null) {
                    throw new GoogleCalendarOAuthException('missing_refresh_token');
                }

                $connection ??= new GoogleCalendarConnection;
                $connection->forceFill([
                    'user_id' => $userId,
                    'provider_account_id' => $grant->providerAccountId,
                    'account_email' => $grant->email,
                    'access_token' => $grant->accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expires_at' => now()->addSeconds($grant->expiresIn),
                    'scopes' => $grant->scopes,
                    'settings' => $connection->settings ?? ['calendarIds' => ['primary'], 'syncEnabled' => true],
                    'sync_cursors' => $sameAccount ? $connection->sync_cursors : null,
                    'connected_at' => now(),
                    'last_synced_at' => $sameAccount ? $connection->last_synced_at : null,
                ])->save();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (GoogleCalendarConnection::query()
                ->where('provider_account_id', $grant->providerAccountId)
                ->where('user_id', '!=', $userId)
                ->exists()) {
                throw new GoogleCalendarOAuthException('account_conflict');
            }
            if ($this->sameUserWonRace($userId, $grant->providerAccountId)) {
                return;
            }

            throw $exception;
        }
    }

    public function sameUserWonRace(int $userId, string $providerAccountId): bool
    {
        return GoogleCalendarConnection::query()
            ->where('user_id', $userId)
            ->where('provider_account_id', $providerAccountId)
            ->exists();
    }
}
