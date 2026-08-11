<?php

namespace Tests\Feature\Sync;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SyncFeedRouteContractTest extends TestCase
{
    public function test_sync_feed_route_preserves_its_method_action_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/sync/feed')
            ->map(static fn (LaravelRoute $route): array => [
                'methods' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => class_basename($route->getActionName()),
                'middleware' => $route->gatherMiddleware(),
                'wheres' => $route->wheres,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'methods' => 'GET|HEAD',
                'uri' => 'api/sync/feed',
                'name' => null,
                'action' => 'ListSyncFeedEntriesController',
                'middleware' => ['api', 'auth:sanctum'],
                'wheres' => [],
            ],
        ], $actualRoutes);
    }

    public function test_sync_feed_route_remains_at_its_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/media-assets/{mediaAssetId}',
            'GET|HEAD api/sync/feed',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/sync/feed',
            'POST api/study/session/start',
        );
    }

    /** @param Collection<int, string> $routeOrder */
    private function assertImmediatelyBefore(Collection $routeOrder, string $before, string $after): void
    {
        $beforeIndex = $routeOrder->search($before, strict: true);
        $afterIndex = $routeOrder->search($after, strict: true);

        $this->assertIsInt($beforeIndex, "Route [$before] is not registered.");
        $this->assertIsInt($afterIndex, "Route [$after] is not registered.");
        $this->assertSame($beforeIndex + 1, $afterIndex, "Route [$before] must remain immediately before [$after].");
    }
}
