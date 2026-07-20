<?php

namespace App\Domain\FeatureFlags\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class FeatureFlagUpdateRateLimiter
{
    public const NAME = 'feature-flag-update';

    public static function limit(Request $request): Limit
    {
        return Limit::perMinute(30)->by('feature-flag-update:user:'.$request->user()->getAuthIdentifier());
    }
}
