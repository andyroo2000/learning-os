<?php

namespace Tests\Feature\Media;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MediaAssetRouteContractTest extends TestCase
{
    public function test_api_media_asset_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/media-assets'
                || str_starts_with($route->uri(), 'api/media-assets/'))
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
            $this->expectedRoute('GET|HEAD', 'api/media-assets', 'ListMediaAssetsController'),
            $this->expectedRoute(
                'POST',
                'api/media-assets',
                'StoreMediaAssetController',
                throttle: 'media-asset-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/media-assets/{mediaAsset}/content',
                'DownloadMediaAssetContentController',
                name: 'api.media-assets.content',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/media-assets/{mediaAsset}',
                'ShowMediaAssetController',
            ),
            $this->expectedRoute(
                'DELETE',
                'api/media-assets/{mediaAssetId}',
                'DeleteMediaAssetController',
                throttle: 'media-asset-delete',
            ),
        ], $actualRoutes);
    }

    public function test_media_asset_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/cards/{card}',
            'GET|HEAD api/media-assets',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/media-assets/{mediaAssetId}',
            'GET|HEAD api/sync/feed',
        );
    }

    /**
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: ?string,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array<string, string>
     * }
     */
    private function expectedRoute(
        string $methods,
        string $uri,
        string $action,
        ?string $throttle = null,
        ?string $name = null,
    ): array {
        $middleware = ['api', 'auth:sanctum'];

        if ($throttle !== null) {
            $middleware[] = 'throttle:'.$throttle;
        }

        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => $name,
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
