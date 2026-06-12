<?php

namespace App\Support\RateLimiting;

final class RateLimitKey
{
    public static function scopedUserOrNetwork(string $scope, mixed $userId, ?string $ip): string
    {
        return $scope.':'.self::userOrNetwork($userId, $ip);
    }

    public static function userOrNetwork(mixed $userId, ?string $ip): string
    {
        if (is_int($userId) || (is_string($userId) && $userId !== '')) {
            return 'user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return 'anon:'.$network;
    }
}
