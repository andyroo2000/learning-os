<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyPreferenceJapaneseRouteContractTest extends TestCase
{
    public function test_study_preference_and_japanese_routes_preserve_registration_order_names_actions_and_middleware(): void
    {
        $uris = [
            'api/study/settings',
            'api/study/known-kanji',
            'api/study/known-kanji/manual',
            'api/study/wanikani',
            'api/study/wanikani/sync',
            'api/study/wanikani/transfer-bridge',
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
                'api/study/settings',
                'ShowStudySettingsController',
                'throttle:study-compatibility-read',
            ),
            $this->expectedRoute(
                'PATCH',
                'api/study/settings',
                'UpdateStudySettingsController',
                'throttle:study-settings-update',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/known-kanji',
                'ShowKnownKanjiController',
                'throttle:study-compatibility-read',
            ),
            $this->expectedRoute(
                'PATCH',
                'api/study/known-kanji/manual',
                'SetManualKnownKanjiController',
                'throttle:known-kanji-manual-write',
            ),
            $this->expectedRoute(
                'PUT',
                'api/study/wanikani',
                'ConnectWaniKaniController',
                'throttle:wanikani-connection-write',
            ),
            $this->expectedRoute(
                'DELETE',
                'api/study/wanikani',
                'DisconnectWaniKaniController',
                'throttle:wanikani-connection-write',
            ),
            $this->expectedRoute(
                'POST',
                'api/study/wanikani/sync',
                'SyncWaniKaniKanjiController',
                'throttle:wanikani-sync',
            ),
            $this->expectedRoute(
                'PATCH',
                'api/study/wanikani/transfer-bridge',
                'UpdateWaniKaniTransferBridgeController',
                'throttle:wanikani-connection-write',
            ),
        ], $actualRoutes);
    }

    public function test_study_preference_and_japanese_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/study/media/{mediaAsset}',
            'GET|HEAD api/study/settings',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'PATCH api/study/wanikani/transfer-bridge',
            'GET|HEAD api/study/google-calendar',
        );
    }

    /**
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: null,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array{}
     * }
     */
    private function expectedRoute(
        string $methods,
        string $uri,
        string $action,
        string $rateLimitMiddleware,
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
                $rateLimitMiddleware,
            ],
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
