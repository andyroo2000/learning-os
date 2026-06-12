<?php

namespace App\Support\RateLimiting;

final class RateLimitKey
{
    private function __construct() {}

    /**
     * Use for operation-scoped buckets such as card-review-event-create:user:42.
     */
    public static function scopedUserOrNetwork(string $scope, mixed $userId, ?string $ip): string
    {
        return $scope.':'.self::userOrNetwork($userId, $ip);
    }

    /**
     * Use for legacy unscoped buckets that intentionally return only user:42 or anon:127.0.0.1.
     */
    public static function userOrNetwork(mixed $userId, ?string $ip): string
    {
        if (is_int($userId) || (is_string($userId) && $userId !== '')) {
            return 'user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return 'anon:'.$network;
    }
}
