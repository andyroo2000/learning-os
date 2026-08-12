<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyMediaRouteContractTest extends TestCase
{
    private const MEDIA_ASSET_PATTERN = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    public function test_study_media_routes_preserve_registration_order_names_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/study/media/batch'
                || $route->uri() === 'api/study/media/{mediaAsset}')
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
            $this->expectedRoute('POST', 'api/study/media/batch', 'DownloadStudyMediaBatchController'),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/media/{mediaAsset}',
                'DownloadMediaAssetContentController',
                ['mediaAsset' => self::MEDIA_ASSET_PATTERN],
            ),
        ], $actualRoutes);
    }

    public function test_study_media_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'PATCH api/study/cards/{cardId}',
            'POST api/study/media/batch',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/study/media/{mediaAsset}',
            'GET|HEAD api/study/settings',
        );
    }

    /**
     * @param  array<string, string>  $wheres
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: null,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array<string, string>
     * }
     */
    private function expectedRoute(
        string $methods,
        string $uri,
        string $action,
        array $wheres = [],
    ): array {
        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => null,
            'action' => $action,
            'middleware' => [
                'api',
                'auth:sanctum',
                'throttle:study-compatibility-network',
                'throttle:study-compatibility-media',
            ],
            'wheres' => $wheres,
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
