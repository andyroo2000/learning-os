<?php

namespace Tests\Unit\FeatureFlags;

use App\Domain\FeatureFlags\Support\FeatureFlagUpdateRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class FeatureFlagUpdateRateLimiterTest extends TestCase
{
    public function test_it_limits_updates_per_authenticated_user(): void
    {
        $request = Request::create('/api/feature-flags', 'PATCH');
        $user = new User;
        $user->setAttribute('id', 42);
        $request->setUserResolver(fn (): User => $user);

        $limit = FeatureFlagUpdateRateLimiter::limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('feature-flag-update:user:42', $limit->key);
    }
}
