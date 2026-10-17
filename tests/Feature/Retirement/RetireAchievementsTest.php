<?php

namespace Tests\Feature\Retirement;

use Tests\TestCase;

class RetireAchievementsTest extends TestCase
{
    public function test_retired_routes_are_not_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri());
        foreach (['api/achievements/catalog', 'api/achievements/progress',
            'api/achievements/evaluate', 'api/study/milestones/evaluate',
            'api/study/milestones/present'] as $uri) {
            $this->assertNotContains($uri, $routes);
        }
    }
}
