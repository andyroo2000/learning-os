<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use Illuminate\Support\Facades\DB;

final class GetGoogleCalendarAccessTokenAction
{
    private const EXPIRY_SKEW_SECONDS = 300;

    public function __construct(private GoogleCalendarReadTransport $google) {}

    public function handle(int $userId): string
    {
        $snapshot = DB::transaction(function () use ($userId): array {
            $connection = GoogleCalendarConnection::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($connection->token_expires_at?->isAfter(now()->addSeconds(self::EXPIRY_SKEW_SECONDS))) {
                return ['access_token' => $connection->access_token];
            }
            if (! is_string($connection->refresh_token) || $connection->refresh_token === '') {
                throw new GoogleCalendarProviderException(GoogleCalendarProviderException::RECONNECT_REQUIRED);
            }

            return [
                'id' => $connection->id,
                'provider_account_id' => $connection->provider_account_id,
                'refresh_token' => $connection->refresh_token,
            ];
        });
        if (isset($snapshot['access_token'])) {
            return $snapshot['access_token'];
        }

        // Provider HTTP stays outside the row-lock transaction. The final
        // compare prevents a stale refresh from overwriting a reconnect.
        $grant = $this->google->refresh($snapshot['refresh_token']);

        return DB::transaction(function () use ($userId, $snapshot, $grant): string {
            $connection = GoogleCalendarConnection::query()
                ->whereKey($snapshot['id'])
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($connection->provider_account_id !== $snapshot['provider_account_id']
                || $connection->refresh_token !== $snapshot['refresh_token']) {
                if ($connection->token_expires_at?->isAfter(now()->addSeconds(self::EXPIRY_SKEW_SECONDS))) {
                    return $connection->access_token;
                }
                throw new GoogleCalendarProviderException(GoogleCalendarProviderException::RECONNECT_REQUIRED);
            }
            $connection->forceFill([
                'access_token' => $grant->accessToken,
                'refresh_token' => $grant->refreshToken ?? $connection->refresh_token,
                'token_expires_at' => now()->addSeconds($grant->expiresIn),
            ])->save();

            return $grant->accessToken;
        });
    }
}
