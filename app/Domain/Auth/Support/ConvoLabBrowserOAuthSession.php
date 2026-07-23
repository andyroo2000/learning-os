<?php

namespace App\Domain\Auth\Support;

use Illuminate\Http\Request;

final class ConvoLabBrowserOAuthSession
{
    public const PENDING_GOOGLE_ACCOUNT = 'convolab.oauth.google.pending_account';

    public const PENDING_MINUTES = 15;

    public static function remember(Request $request, string $convoLabUserId): void
    {
        $request->session()->put(self::PENDING_GOOGLE_ACCOUNT, [
            'convolab_user_id' => $convoLabUserId,
            'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
        ]);
    }

    public static function pending(Request $request): ?string
    {
        $value = $request->session()->get(self::PENDING_GOOGLE_ACCOUNT);
        $convoLabUserId = is_array($value) ? ($value['convolab_user_id'] ?? null) : null;
        $expiresAt = is_array($value) ? ($value['expires_at'] ?? null) : null;
        if (
            ! is_string($convoLabUserId)
            || $convoLabUserId === ''
            || ! is_int($expiresAt)
            || $expiresAt < now()->getTimestamp()
        ) {
            self::forget($request);

            return null;
        }

        return $convoLabUserId;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::PENDING_GOOGLE_ACCOUNT);
    }
}
