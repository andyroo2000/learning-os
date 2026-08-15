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
        return DB::transaction(function () use ($userId): string {
            $connection = GoogleCalendarConnection::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($connection->token_expires_at?->isAfter(now()->addSeconds(self::EXPIRY_SKEW_SECONDS))) {
                return $connection->access_token;
            }
            if (! is_string($connection->refresh_token) || $connection->refresh_token === '') {
                throw new GoogleCalendarProviderException(GoogleCalendarProviderException::RECONNECT_REQUIRED);
            }

            $grant = $this->google->refresh($connection->refresh_token);
            $connection->forceFill([
                'access_token' => $grant->accessToken,
                'refresh_token' => $grant->refreshToken ?? $connection->refresh_token,
                'token_expires_at' => now()->addSeconds($grant->expiresIn),
            ])->save();

            return $grant->accessToken;
        });
    }
}
