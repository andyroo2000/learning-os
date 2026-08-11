<?php

namespace Tests\Feature\FeatureFlags;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeatureFlagRouteContractTest extends TestCase
{
    public function test_api_feature_flag_routes_preserve_registration_order_actions_and_middleware(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/feature-flags')
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
            $this->expectedRoute('GET|HEAD', 'ShowFeatureFlagsController'),
            $this->expectedRoute(
                'PATCH',
                'UpdateFeatureFlagsController',
                'feature-flag-update',
            ),
        ], $actualRoutes);
    }

    public function test_feature_flag_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/convolab/courses/{courseId}/audio',
            'GET|HEAD api/feature-flags',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'PATCH api/feature-flags',
            'PUT api/me',
        );
    }

    /**
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: null,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array<string, string>
     * }
     */
    private function expectedRoute(string $methods, string $action, ?string $throttle = null): array
    {
        $middleware = ['api', 'auth:sanctum'];

        if ($throttle !== null) {
            $middleware[] = 'throttle:'.$throttle;
        }

        return [
            'methods' => $methods,
            'uri' => 'api/feature-flags',
            'name' => null,
            'action' => $action,
            'middleware' => $middleware,
            'wheres' => [],
        ];
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
