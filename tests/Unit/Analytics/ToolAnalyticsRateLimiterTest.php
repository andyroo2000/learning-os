<?php

namespace Tests\Unit\Analytics;

use App\Domain\Analytics\Support\ToolAnalyticsRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ToolAnalyticsRateLimiterTest extends TestCase
{
    public function test_limiter_has_aggregate_proxy_capacity_and_a_scoped_key(): void
    {
        $request = Request::create('/api/convolab/tools/analytics', 'POST');
        $user = new User;
        $user->id = 42;
        $request->setUserResolver(fn (): User => $user);

        $limit = ToolAnalyticsRateLimiter::limit($request);

        $this->assertSame(6000, $limit->maxAttempts);
        $this->assertSame('tool-analytics-store:user:42', $limit->key);
    }
}
