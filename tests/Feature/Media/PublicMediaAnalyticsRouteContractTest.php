<?php

namespace Tests\Feature\Media;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicMediaAnalyticsRouteContractTest extends TestCase
{
    public function test_public_media_and_analytics_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $uris = [
            'api/avatars/{avatarPath}',
            'api/tools-audio/signed-urls',
            'api/convolab/browser/tools/analytics',
        ];

        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => in_array($route->uri(), $uris, strict: true))
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
            $this->expectedRoute(
                'GET|HEAD',
                'api/avatars/{avatarPath}',
                'ShowAvatarAssetController',
                wheres: ['avatarPath' => '.*'],
            ),
            $this->expectedRoute(
                'POST',
                'api/tools-audio/signed-urls',
                'ResolveToolAudioUrlsController',
                throttle: 'tool-audio-signed-url',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/browser/tools/analytics',
                'StoreBrowserToolAnalyticsEventController',
                throttle: 'browser-tool-analytics-store',
            ),
        ], $actualRoutes);
    }

    public function test_public_media_and_analytics_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/achievements/catalog',
            'GET|HEAD api/avatars/{avatarPath}',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/avatars/{avatarPath}',
            'POST api/tools-audio/signed-urls',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/tools-audio/signed-urls',
            'POST api/convolab/browser/tools/analytics',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/convolab/browser/tools/analytics',
            'POST api/auth/register',
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
        ?string $throttle = null,
        array $wheres = [],
    ): array {
        $middleware = ['api'];

        if ($throttle !== null) {
            $middleware[] = 'throttle:'.$throttle;
        }

        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => null,
            'action' => $action,
            'middleware' => $middleware,
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
